#!/usr/bin/env python3
"""Run every private standalone unit/contract file, without live-site mutation."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import json
from pathlib import Path
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
REPORT = Path(tempfile.mkdtemp(prefix="wpmmcc-unit-", dir=ROOT / "tests/.cache"))
FILES = sorted([*(HERE / "unit").glob("test-*.php"), *(HERE / "contract").glob("test-*.php")])


def run(path):
    relative = str(path.relative_to(HERE))
    log = REPORT / (path.parent.name + "-" + path.name + ".log")
    with log.open("x") as output:
        try:
            result = subprocess.run(["php", str(path)], stdout=output, stderr=subprocess.STDOUT, timeout=120)
            code = result.returncode
        except subprocess.TimeoutExpired:
            code = 124
    return {"file": relative, "exit_code": code, "status": "passed" if code == 0 else "failed", "log": str(log)}


if __name__ == "__main__":
    if not FILES:
        raise SystemExit("private PHP inventory is empty; no false green")
    with ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(run, FILES))
    for item in results:
        print(f"{item['status'].upper()} {item['file']} ({item['exit_code']})", flush=True)
    passed = sum(item["exit_code"] == 0 for item in results)
    payload = {"format": "wpmmcc-private-unit-v1", "generated_at": datetime.now(timezone.utc).isoformat(),
               "passed_files": passed, "failed_files": len(results) - passed,
               "live_e2e_run": False, "results": results}
    (REPORT / "summary.json").write_text(json.dumps(payload, indent=2) + "\n")
    print(f"Summary: Total {len(results)} | Passed {passed} | Failed {len(results) - passed}")
    print(f"REPORT={REPORT}")
    raise SystemExit(0 if passed == len(results) else 1)
