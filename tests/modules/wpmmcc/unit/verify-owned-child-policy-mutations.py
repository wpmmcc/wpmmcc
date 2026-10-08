#!/usr/bin/env python3
"""Private compiled native mutations, changing only a verified owned WP copy."""

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[4]
SPEC = importlib.util.spec_from_file_location("owned_child_mutations", ROOT / "tests/infra/tools/owned-wp-sites.py")
OWNED = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(OWNED)
RELATIVE = "includes/sync/class-wpmmcc-write-policy.php"
NATIVE = "/var/www/html/wp-content/plugins/wpmmcc/" + RELATIVE


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context", type=Path, required=True)
    parser.add_argument("--identities", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    context = OWNED.load(args.context)
    site = context["sites"][1]
    frozen = json.loads(args.identities.read_text())
    expected = next(row for row in frozen["sites"] if row["name"] == site["name"])
    info = json.loads(subprocess.check_output(["docker", "inspect", expected["id"]], text=True))[0]
    settings = dict(value.split("=", 1) for value in info["Config"]["Env"] if "=" in value)
    if (info["Id"] != expected["id"] or info["Name"] != "/" + site["name"]
            or info["Config"]["Labels"].get(OWNED.LABEL) != context["owner"]
            or frozen["owner"] != context["owner"]
            or settings.get("WORDPRESS_TABLE_PREFIX") != site["prefix"]
            or expected["prefix"] != site["prefix"]):
        raise ValueError("owned frozen identity mismatch")
    container = info["Id"]
    original = (ROOT / "wpmmcc/source" / RELATIVE).read_bytes()

    def native_hash():
        return subprocess.check_output(
            ["docker", "exec", container, "php", "-r", "echo hash_file('sha256',$argv[1]);", NATIVE],
            text=True, timeout=30,
        ).strip()

    source_hash = hashlib.sha256(original).hexdigest()
    if native_hash() != source_hash:
        raise ValueError("owned copy differs from current candidate")

    def install(data):
        result = subprocess.run(
            ["docker", "exec", "-i", container, "php", "-r",
             "$n=file_put_contents($argv[1],stream_get_contents(STDIN));"
             "if($n===false || !chmod($argv[1],0644)) exit(1);", NATIVE],
            input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30,
        )
        if result.returncode or native_hash() != hashlib.sha256(data).hexdigest():
            raise RuntimeError("owned mutation install failed")

    def probe(label):
        compile_result = subprocess.run(
            ["docker", "exec", container, "php", "-l", NATIVE],
            capture_output=True, text=True, timeout=30,
        )
        if compile_result.returncode:
            raise RuntimeError("mutation compile failed; not a kill")
        report = args.report.parent / (args.report.stem + "-" + label + ".json")
        result = subprocess.run(
            ["python3", str(ROOT / "tests/modules/wpmmcc/unit/verify-owned-adapter-child-policy.py"),
             "--context", str(args.context), "--report", str(report)],
            capture_output=True, text=True, timeout=60,
        )
        (args.report.parent / (args.report.stem + "-" + label + ".log")).write_text(result.stdout + result.stderr)
        if not report.exists():
            raise RuntimeError("native fixture failed before verdict; not a kill")
        cases = json.loads(report.read_text())["checks"]
        failures = [row["name"] for row in cases if not row["passed"]]
        if result.returncode not in (0, 1) or (result.returncode == 0) != (not failures):
            raise RuntimeError("invalid native mutation verdict")
        return {"name": label, "compiled": True, "exit_code": result.returncode,
                "passed": len(cases) - len(failures), "failed": failures}

    baseline = probe("baseline")
    if baseline["exit_code"] or baseline["passed"] == 0:
        raise RuntimeError("baseline failed; no mutation kills counted")
    text = original.decode()
    replacements = [
        ("child-group-gate", "if ( ! self::allows_field( $group, $context ) ) {", "if ( false ) {"),
        ("unselected-core-gate", "|| ! self::allows_field( (string) $field, $object_context ) ) {", "|| false ) {"),
    ]
    mutants = []
    try:
        for label, before, after in replacements:
            if text.count(before) != 1:
                raise ValueError("mutation target must be unique")
            install(text.replace(before, after).encode())
            try:
                verdict = probe(label)
                verdict["killed"] = verdict["exit_code"] == 1 and bool(verdict["failed"])
                mutants.append(verdict)
            finally:
                install(original)
        restored = probe("restored")
        if restored["exit_code"]:
            raise RuntimeError("restored candidate failed")
    finally:
        install(original)
    report = {"source_sha256": source_hash, "baseline": baseline, "mutants": mutants,
              "restored": restored, "owned_copy_restored": native_hash() == source_hash}
    args.report.write_text(json.dumps(report, indent=2) + "\n")
    print("RESULT baseline/restored native cases passed; compiled mutants killed:",
          sum(row["killed"] for row in mutants), "/", len(mutants))
    return 0 if all(row["killed"] for row in mutants) else 1


if __name__ == "__main__":
    raise SystemExit(main())
