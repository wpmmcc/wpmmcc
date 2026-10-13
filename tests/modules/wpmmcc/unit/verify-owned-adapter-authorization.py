#!/usr/bin/env python3
"""Private native WP tests for all adapter channels and merge ownership."""

import argparse
import importlib.util
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "owned_wp_adapter_auth", ROOT / "tests/infra/tools/owned-wp-sites.py"
)
owned_wp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(owned_wp)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    context = owned_wp.load(args.context)
    site = context["sites"][1]
    label = subprocess.check_output(
        ["docker", "inspect", site["name"], "--format",
         '{{index .Config.Labels "com.wptsall.owned.run"}}'], text=True
    ).strip()
    if label != context["owner"]:
        raise ValueError("owned identity mismatch")
    checks = json.loads(owned_wp.wp(site["name"], """
use WPMMCC\\Adapters\\Adapter_Generic as G;
use WPMMCC\\Adapters\\Adapter_ACF as A;
use WPMMCC\\Adapters\\Adapter_Woocommerce as W;
use WPMMCC\\Adapters\\Adapter_Registry as R;
use WPMMCC\\Adapters\\Adapter_Interface as I;
use WPMMCC\\Sync\\Shadow_Draft as D;
$id=wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_title'=>'Owned adapter'),true);
if(is_wp_error($id)) throw new RuntimeException('owned native adapter fixture');
$canonical=get_post_meta($id,'_wpmmcc_canonical_uuid',true);
update_post_meta($id,'_wptsall_relation_id',17);
update_post_meta($id,'owned_unchanged','Local value');
$g=new G();
$g->import_custom_payload($id,array('meta_fields'=>array(
 '_wpmmcc_canonical_uuid'=>'wire-hijack',D::META_FLAG=>1,
 '_wptsall_relation_id'=>999,'owned_caption'=>'Native caption')));
$checks=array(
 'generic cannot overwrite canonical UUID'=>get_post_meta($id,'_wpmmcc_canonical_uuid',true)===$canonical,
 'generic cannot create review membership'=>!D::is_shadow($id),
 'generic cannot overwrite ATS routing'=>(int)get_post_meta($id,'_wptsall_relation_id',true)===17,
 'generic keeps legal custom content'=>get_post_meta($id,'owned_caption',true)==='Native caption'
);
$quoted=wp_json_encode(array('caption'=>'Translated "quoted" and \\\\ path'));
$g->import_custom_payload($id,array('meta_fields'=>array('owned_json'=>$quoted)));
$checks['generic preserves quoted structured metadata']=get_post_meta($id,'owned_json',true)===$quoted;
$acf=new A();
$acf->import_custom_payload($id,array('has_acf'=>true,'fields'=>array(
 '_wpmmcc_canonical_uuid'=>array('value'=>'acf-hijack','field_key'=>'field_owned'),
 'owned_group'=>array('value'=>array('caption'=>'ACF caption'),'field_key'=>'field_owned'))));
$checks['ACF value and reference cannot rewrite identity']=get_post_meta($id,'_wpmmcc_canonical_uuid',true)===$canonical
 && get_post_meta($id,'__wpmmcc_canonical_uuid',true)==='';
$checks['ACF native group and reference survive']=get_post_meta($id,'owned_group',true)===array('caption'=>'ACF caption')
 && get_post_meta($id,'_owned_group',true)==='field_owned';
$woo=new W();
$woo->import_custom_payload($id,array('product_type'=>'variable','variations'=>array(array(
 'sku'=>'owned-variation','meta_fields'=>array('_sku'=>'owned-variation',
  '_wpmmcc_canonical_uuid'=>'variation-hijack',D::META_FLAG=>1)))));
$vars=get_posts(array('post_type'=>'product_variation','post_parent'=>$id,'post_status'=>'any','numberposts'=>-1));
$variation=$vars[0]->ID ?? 0;
$checks['Woo variation cannot import local identity']=$variation>0
 && get_post_meta($variation,'_wpmmcc_canonical_uuid',true)!=='variation-hijack' && !D::is_shadow($variation);
$checks['Woo variation keeps SKU']=$variation>0 && get_post_meta($variation,'_sku',true)==='owned-variation';
if(method_exists(R::class,'import_payload')) {
 $context=array('changed_fields'=>array('owned_caption'), 'delta_merge'=>true,
  'protected_fields'=>array('owned_protected'));
 R::import_payload($g,$id,array('meta_fields'=>array(
  'owned_caption'=>'Delta caption','owned_unchanged'=>'Wire value','owned_protected'=>'Wire protected',
  '_wpmmcc_canonical_uuid'=>'delta-hijack')), $context);
 $checks['plugin_specific honors delta field ownership']=get_post_meta($id,'owned_caption',true)==='Delta caption'
  && get_post_meta($id,'owned_unchanged',true)==='Local value'
  && get_post_meta($id,'owned_protected',true)==='';
 $foreign=wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_title'=>'Owned foreign adapter object'));
 update_post_meta($foreign,'owned_caption','Foreign original');
 $custom=new class($foreign) implements I {
  private $foreign;
  public function __construct(int $foreign) { $this->foreign=$foreign; }
  public function get_slug(): string { return 'owned-third-party'; }
  public function get_supported_post_types(): array { return array('post'); }
  public function is_plugin_active(): bool { return true; }
  public function export_custom_payload(int $id,$post): array { return array(); }
  public function get_translatable_fields(string $type): array { return array('owned_caption'); }
  public function import_custom_payload(int $id,array $payload,array $context=array()): bool {
   update_post_meta($id,'owned_caption','Third party caption');
   add_post_meta($id,'owned_protected','Third party protected');
   delete_post_meta($id,'owned_unchanged');
   update_post_meta($id,'_wpmmcc_canonical_uuid','third-party-hijack');
   update_post_meta($this->foreign,'owned_caption','Foreign hijack');
   throw new RuntimeException('owned adapter failure');
  }
 };
 try { R::import_payload($custom,$id,array('owned'=>true),$context); } catch(RuntimeException $e) {}
 $checks['third-party WP APIs share identity and delta guard']=get_post_meta($id,'owned_caption',true)==='Third party caption'
  && get_post_meta($id,'owned_unchanged',true)==='Local value'
  && get_post_meta($id,'owned_protected',true)===''
  && get_post_meta($id,'_wpmmcc_canonical_uuid',true)===$canonical;
 $checks['adapter cannot write metadata of an unrelated local object']=get_post_meta($foreign,'owned_caption',true)==='Foreign original';
 update_post_meta($id,'owned_unchanged','Local after exception');
 $checks['adapter exception releases scoped guard']=get_post_meta($id,'owned_unchanged',true)==='Local after exception';
} else {
 $checks['plugin_specific honors delta field ownership']=false;
 $checks['third-party WP APIs share identity and delta guard']=false;
 $checks['adapter exception releases scoped guard']=false;
}
foreach($vars as $var) wp_delete_post($var->ID,true);
if(isset($foreign)) wp_delete_post($foreign,true);
wp_delete_post($id,true);
echo wp_json_encode($checks);
"""))
    rows = [{"name": name, "passed": bool(passed)} for name, passed in checks.items()]
    for row in rows:
        print(("PASS " if row["passed"] else "FAIL ") + row["name"])
    report = {"kind": "owned-wp-adapter-authorization",
              "passed": sum(row["passed"] for row in rows),
              "failed": sum(not row["passed"] for row in rows), "checks": rows}
    args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(f"RESULT {report['passed']} passed, {report['failed']} failed")
    return 1 if report["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
