#!/usr/bin/env python3
"""Private real HTTP HMAC push, native metadata and unauthenticated budget checks."""

import argparse
import hashlib
import hmac
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location("owned_wp_push", ROOT / "tests/infra/tools/owned-wp-sites.py")
owned_wp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(owned_wp)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    context = owned_wp.load(args.context)
    site = context["sites"][1]
    info = json.loads(subprocess.check_output(["docker", "inspect", site["name"]], text=True))[0]
    settings = dict(value.split("=", 1) for value in info["Config"]["Env"] if "=" in value)
    if (info["Config"]["Labels"].get(owned_wp.LABEL) != context["owner"]
            or settings.get("WORDPRESS_TABLE_PREFIX") != site["prefix"]
            or info["Name"] != "/" + site["name"]
            or not re.fullmatch(r"[a-f0-9]{64}", info["Id"])):
        raise ValueError("owned identity mismatch")
    # Use the preflighted immutable ID, never a reusable name, for all writes.
    container = info["Id"]
    peer = "urn:uuid:" + str(uuid.uuid4())
    secret = uuid.uuid4().hex + uuid.uuid4().hex
    guid = "urn:uuid:" + str(uuid.uuid4())
    rows = []

    def check(name, passed):
        rows.append({"name": name, "passed": bool(passed)})
        print(("PASS " if passed else "FAIL ") + name)

    owned_wp.wp(container, f"""
global $wpdb;
$salt=bin2hex(random_bytes(16)); $now=current_time('mysql');
$inserted=$wpdb->insert($wpdb->prefix.'wpmmcc_peers',array(
 'peer_uuid'=>{json.dumps(peer)},'peer_name'=>'Owned signed push',
 'endpoint_url'=>'https://owned-peer.example.invalid',
 'shared_secret'=>WPMMCC\\REST\\Rest_Middleware::encrypt_shared_secret({json.dumps(secret)},$salt),
 'key_salt'=>$salt,'direction'=>'bidirectional','sync_mode'=>'sync_only',
 'conflict_strategy'=>'merge','status'=>'active','created_at'=>$now,'updated_at'=>$now));
if(false===$inserted) throw new RuntimeException('owned peer fixture failed');
""")
    # Limit only this randomly generated fixture peer, never other callers.
    fixture_mu = "/var/www/html/wp-content/mu-plugins/owned-signed-push-" + uuid.uuid4().hex + ".php"
    php = "<?php\nadd_filter('wpmmcc_rate_limit_per_minute',static function($limit,$bucket) { return $bucket===" \
          + json.dumps("peer|" + peer) + " || strpos($bucket,'preauth|ip|')===0 ? 3 : $limit; },10,2);\n"
    result = subprocess.run(["docker", "exec", "-i", container, "php", "-r",
        "$f=fopen($argv[1],'x'); if(!$f) exit(1); fwrite($f,stream_get_contents(STDIN)); fclose($f);", fixture_mu],
        input=php, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
    if result.returncode:
        raise RuntimeError("owned budget fixture installation failed")
    quoted = json.dumps({"caption": 'Native "quoted" and \\ path'}, separators=(",", ":"))
    packet = {
        "schema_version": "wpmmcc-sync-v1.0", "packet_id": "pkt_" + uuid.uuid4().hex,
        "origin_context": {"origin_site_uuid": peer, "origin_site_url": "https://owned-peer.example.invalid",
            "origin_blog_id": 1, "origin_lang": "en_US", "vector_clock": {peer: 1},
            "hop_count": 1, "dispatch_timestamp": int(time.time())},
        "action": "upsert", "sync_mode": "sync_only", "target_lang": "zh_CN",
        "source_fingerprint": hashlib.sha256(guid.encode()).hexdigest(),
        "entity": {"guid": guid, "object_type": "post", "subtype": "post", "source_id": 42,
            "slug": "owned-signed-push", "status": "draft",
            "core_fields": {"post_title": "Owned HTTP title", "post_content": "<p>Owned native content</p>",
                "post_excerpt": "Owned excerpt"},
            "meta_fields": {"_wpmmcc_canonical_uuid": "wire-hijack", "_wptsall_relation_id": 999,
                "_wpmmcc_is_shadow_draft": 1, "owned_top_json": quoted},
            "taxonomies": {},
            "plugin_specific": {"meta_fields": {"_wpmmcc_canonical_uuid": "adapter-hijack",
                "_wpmmcc_is_shadow_draft": 1, "owned_adapter_json": quoted}}},
        "multimodal_manifest": [],
    }
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def push(value, valid=True, reuse=None):
        body = json.dumps(value, separators=(",", ":"), ensure_ascii=False).encode()
        timestamp, nonce = reuse or (int(time.time()), uuid.uuid4().hex)
        canonical = "\n".join(["POST", "/wpmmcc/v1/sync/push", str(timestamp), nonce,
            peer, hashlib.sha256(body).hexdigest()]).encode()
        signature = hmac.new(secret.encode(), canonical, hashlib.sha256).hexdigest() if valid else "0" * 64
        headers = {"Content-Type": "application/json", "X-WPMMCC-Site-UUID": peer,
            "X-WPMMCC-Timestamp": str(timestamp), "X-WPMMCC-Nonce": nonce, "X-WPMMCC-Signature": signature}
        request = urllib.request.Request(site["base_url"] + "/?rest_route=/wpmmcc/v1/sync/push",
            data=body, headers=headers, method="POST")
        try:
            with opener.open(request, timeout=20) as response:
                return response.status, json.load(response), (timestamp, nonce)
        except urllib.error.HTTPError as error:
            return error.code, json.loads(error.read()), (timestamp, nonce)

    try:
        rejects = [push(packet, valid=False)[0] for _ in range(5)]
        native_count = int(owned_wp.wp(container, f"""
global $wpdb; echo $wpdb->get_var($wpdb->prepare(
 'SELECT COUNT(*) FROM '.$wpdb->prefix.'wpmmcc_cross_mappings WHERE canonical_uuid=%s',
 {json.dumps(guid)}));
"""))
        check("invalid HMAC never materializes content",
            all(status in (401, 403, 429) for status in rejects) and native_count == 0)
        check("unauthenticated failures have a separate bounded budget", rejects[-1] == 429)
        check("inactive content plugin cannot intercept generic post adapter",
            owned_wp.wp(container, "echo WPMMCC\\Adapters\\Adapter_Registry::get_for_post_type('post')->get_slug();") == "generic")
        status, response, envelope = push(packet)
        target_id = int(response.get("data", {}).get("target_id", 0))
        check("forged peer traffic cannot spend authenticated peer budget", status == 200 and target_id > 0)
        if target_id:
            native = json.loads(owned_wp.wp(container, f"""
$id={target_id}; $post=get_post($id);
echo wp_json_encode(array(
 'signed packet writes native core content'=>$post && $post->post_title==='Owned HTTP title',
 'all packet channels preserve canonical identity'=>get_post_meta($id,'_wpmmcc_canonical_uuid',true)==={json.dumps(guid)},
 'packet cannot create review or ATS routing'=>!WPMMCC\\Sync\\Shadow_Draft::is_shadow($id)
   && get_post_meta($id,'_wptsall_relation_id',true)==='',
 'top-level JSON retains escapes'=>get_post_meta($id,'owned_top_json',true)==={json.dumps(quoted)},
 'adapter JSON retains escapes'=>get_post_meta($id,'owned_adapter_json',true)==={json.dumps(quoted)}
));
"""))
            for name, passed in native.items():
                check(name, passed)
            replay_status, _, _ = push(packet, reuse=envelope)
            check("same signed nonce is rejected on replay", replay_status in (403, 429))
            # A local drift forces the receiver's real merge verdict.
            owned_wp.wp(container, f"""
global $wpdb; $id={target_id};
wp_update_post(array('ID'=>$id,'post_title'=>'Local title','post_status'=>'publish'));
update_post_meta($id,'owned_unchanged','Local unchanged');
$term=wp_insert_term('Owned local category {guid[-8:]}','category');
if(is_wp_error($term)) throw new RuntimeException('owned taxonomy fixture');
wp_set_object_terms($id,array((int)$term['term_id']),'category');
update_post_meta($id,'owned_expected_term_id',(int)$term['term_id']);
$wpdb->update($wpdb->prefix.'wpmmcc_cross_mappings',
 array('last_synced_at'=>gmdate('Y-m-d H:i:s',time()-30)),
 array('canonical_uuid'=>{json.dumps(guid)},'peer_uuid'=>{json.dumps(peer)}));
""")
            packet["packet_id"] = "pkt_" + uuid.uuid4().hex
            packet["origin_context"]["vector_clock"][peer] = 2
            packet["source_fingerprint"] = hashlib.sha256(packet["packet_id"].encode()).hexdigest()
            packet["entity"]["changed_fields"] = ["owned_caption"]
            packet["entity"]["taxonomies"] = {"category": [{"name": "Remote unselected category " + guid[-8:],
                "slug": "owned-remote-" + guid[-8:], "term_id": 51}]}
            packet["entity"]["plugin_specific"] = {"meta_fields": {"owned_caption": "Remote delta caption",
                "owned_unchanged": "Remote unselected", "_wpmmcc_canonical_uuid": "delta-hijack"}}
            status, response, _ = push(packet)
            check("authenticated delta packet is accepted", status == 200)
            native = json.loads(owned_wp.wp(container, f"""
$id={target_id}; $post=get_post($id);
echo wp_json_encode(array(
 'signed delta preserves unselected core and publish status'=>$post->post_title==='Local title' && $post->post_status==='publish',
 'signed delta applies only selected adapter metadata'=>get_post_meta($id,'owned_caption',true)==='Remote delta caption'
   && get_post_meta($id,'owned_unchanged',true)==='Local unchanged'
   && get_post_meta($id,'_wpmmcc_canonical_uuid',true)==={json.dumps(guid)},
 'signed delta preserves unselected native taxonomy'=>wp_get_object_terms($id,'category',array('fields'=>'ids'))
   ===array((int)get_post_meta($id,'owned_expected_term_id',true))
));
"""))
            for name, passed in native.items():
                check(name, passed)
            packet["packet_id"] = "pkt_" + uuid.uuid4().hex
            packet["origin_context"]["vector_clock"][peer] = 3
            packet["source_fingerprint"] = hashlib.sha256(packet["packet_id"].encode()).hexdigest()
            packet["entity"]["changed_fields"] = ["category"]
            status, _, _ = push(packet)
            check("signed taxonomy selection remains writable", status == 200 and owned_wp.wp(container, f"""
$terms=wp_get_object_terms({target_id},'category',array('fields'=>'slugs'));
echo $terms===array({json.dumps("owned-remote-" + guid[-8:])}) ? 'selected' : 'not-selected';
""") == "selected")
            # Account for a 60-second window rollover while native reads run.
            limited = [push(packet)[0] for _ in range(4)]
            check("authenticated peer budget still enforces its own limit", 429 in limited)
    finally:
        subprocess.run(["docker", "exec", container, "php", "-r",
            "if(is_file($argv[1])) unlink($argv[1]);", fixture_mu], check=True,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)
    report = {"kind": "owned-wp-signed-push", "passed": sum(row["passed"] for row in rows),
        "failed": sum(not row["passed"] for row in rows), "checks": rows}
    args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(f"RESULT {report['passed']} passed, {report['failed']} failed")
    return int(report["failed"] > 0)


if __name__ == "__main__":
    raise SystemExit(main())
