#!/usr/bin/env python3
"""Private native Woo/adapter object, child-field and receiver-identity probes."""

import argparse
import importlib.util
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[4]
SPEC = importlib.util.spec_from_file_location("owned_child_policy", ROOT / "tests/infra/tools/owned-wp-sites.py")
OWNED = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(OWNED)

PHP = r'''
use WPMMCC\Adapters\Adapter_Woocommerce as W;
use WPMMCC\Adapters\Adapter_Registry as R;
use WPMMCC\Adapters\Adapter_Interface as I;
$GLOBALS['owned_child_policy_checks']=array(); $checks=&$GLOBALS['owned_child_policy_checks'];
$GLOBALS['owned_child_policy_posts']=array(); $created=&$GLOBALS['owned_child_policy_posts'];
function owned_child_check($name,$value) { $GLOBALS['owned_child_policy_checks'][$name]=(bool)$value; }
function owned_child_post($data) {
 $id=wp_insert_post($data,true);
 if(is_wp_error($id) || !$id) throw new RuntimeException('owned native post fixture failed');
 $GLOBALS['owned_child_policy_posts'][]=$id; return $id;
}
function owned_child_terms($id,$taxonomy) { return wp_get_object_terms($id,$taxonomy,array('fields'=>'slugs')); }
$root=owned_child_post(array('post_type'=>'product','post_status'=>'draft','post_title'=>'Local product'));
$local_content='<p>Local "quoted" and \\ path</p>';
wp_update_post(wp_slash(array('ID'=>$root,'post_content'=>$local_content)));
$root_content=get_post($root)->post_content;
$child=owned_child_post(array('post_type'=>'product_variation','post_status'=>'private',
 'post_parent'=>$root,'post_title'=>'Local variation','menu_order'=>7));
update_post_meta($child,'_sku','owned-existing-sku');
update_post_meta($child,'_regular_price','10'); update_post_meta($child,'_price','10');
wp_set_object_terms($root,'simple','product_type');
$child_before=(array)get_post($child);
$payload=array('product_type'=>'variable','price'=>'99','variations'=>array(
 array('sku'=>'owned-existing-sku','menu_order'=>88,'meta_fields'=>array('_price'=>'99','_regular_price'=>'99')),
 array('sku'=>'owned-injected-sku','meta_fields'=>array('_sku'=>'owned-injected-sku','_price'=>'99'))));
$woo=new W();
try {
 R::import_payload($woo,$root,$payload,array('delta_merge'=>true,'changed_fields'=>array('post_title'),
  'protected_fields'=>array('post_status')));
 owned_child_check('unselected product_type is preserved',owned_child_terms($root,'product_type')===array('simple'));
 $post=get_post($child);
 owned_child_check('unselected variation core is preserved',$post->post_title===$child_before['post_title']
  && $post->post_status===$child_before['post_status'] && (int)$post->menu_order===(int)$child_before['menu_order']);
 $children=get_posts(array('post_type'=>'product_variation','post_parent'=>$root,'post_status'=>'any','numberposts'=>-1));
 owned_child_check('unselected variations cannot create children',count($children)===1);
 owned_child_check('unselected variation meta is preserved',get_post_meta($child,'_price',true)==='10');

 $selected=array('product_type'=>'variable','variations'=>array(
  array('sku'=>'owned-existing-sku','menu_order'=>99,'meta_fields'=>array('_price'=>'12','_regular_price'=>'12'))));
 R::import_payload($woo,$root,$selected,array('delta_merge'=>true,'changed_fields'=>array('variations'),
  'protected_fields'=>array('post_status','menu_order')));
 $post=get_post($child);
 owned_child_check('selected variations preserve local publication',$post->post_status==='private');
 owned_child_check('protected child core is preserved',(int)$post->menu_order===7);
 owned_child_check('selected variation meta remains writable',get_post_meta($child,'_price',true)==='12');
 owned_child_check('child selection does not select parent taxonomy',owned_child_terms($root,'product_type')===array('simple'));

 R::import_payload($woo,$root,array('product_type'=>'variable'),array('delta_merge'=>true,
  'changed_fields'=>array('product_type'),'protected_fields'=>array('post_status')));
 owned_child_check('explicit product_type selection works',owned_child_terms($root,'product_type')===array('variable'));

 $fresh=owned_child_post(array('post_type'=>'product','post_status'=>'draft','post_title'=>'Fresh product'));
 R::import_payload($woo,$fresh,array('product_type'=>'variable','variations'=>array(
  array('sku'=>'owned-new-sku','meta_fields'=>array('_sku'=>'owned-new-sku','_price'=>'15',
   '_wpmmcc_canonical_uuid'=>'owned-wire-identity','_wpmmcc_shadow'=>1)))),array());
 $new=get_posts(array('post_type'=>'product_variation','post_parent'=>$fresh,'post_status'=>'any','numberposts'=>-1));
 $new_id=$new[0]->ID ?? 0;
 if($new_id) $created[]=$new_id;
 owned_child_check('full native variation creation remains supported',$new_id>0
  && get_post_meta($new_id,'_sku',true)==='owned-new-sku');
 $uuid=get_post_meta($new_id,'_wpmmcc_canonical_uuid',true);
 owned_child_check('new child gets a receiver-generated stored UUID',is_string($uuid)
  && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$uuid)
  && $uuid!=='owned-wire-identity');
 $ingested=owned_child_post(array('post_type'=>'product','post_status'=>'draft','post_title'=>'Ingested product'));
 WPMMCC\Sync\Event_Recorder::run_in_ingestion_scope(static function() use($woo,$ingested) {
  R::import_payload($woo,$ingested,array('product_type'=>'variable','variations'=>array(
   array('sku'=>'owned-ingested-sku','meta_fields'=>array('_sku'=>'owned-ingested-sku')))),array());
 });
 $ingested_children=get_posts(array('post_type'=>'product_variation','post_parent'=>$ingested,'post_status'=>'any','numberposts'=>-1));
 $ingested_id=$ingested_children[0]->ID ?? 0;
 if($ingested_id) $created[]=$ingested_id;
 owned_child_check('ingestion scope child identity is stored without CDC hooks',$ingested_id>0
  && get_post_meta($ingested_id,'_wpmmcc_canonical_uuid',true)!=='');
 $failed_root=owned_child_post(array('post_type'=>'product','post_status'=>'draft','post_title'=>'Failed identity product'));
 $fail_identity=static function($check,$id,$key) use($failed_root) {
  return $key==='_wpmmcc_canonical_uuid' && (int)get_post($id)->post_parent===$failed_root ? false : $check;
 };
 add_filter('update_post_metadata',$fail_identity,10,3);
 try {
  $failed_import=R::import_payload($woo,$failed_root,array('product_type'=>'variable','variations'=>array(
   array('sku'=>'owned-failed-sku','meta_fields'=>array('_sku'=>'owned-failed-sku')))),array());
 } finally { remove_filter('update_post_metadata',$fail_identity,10); }
 $failed_children=get_posts(array('post_type'=>'product_variation','post_parent'=>$failed_root,'post_status'=>'any','numberposts'=>-1));
 $failed_child=$failed_children[0]->ID ?? 0;
 if($failed_child) $created[]=$failed_child;
 owned_child_check('failed identity persistence does not claim import success',$failed_import===false);
 owned_child_check('failed identity is never published only in cache',$failed_child>0
  && WPMMCC\Sync\Event_Recorder::lookup_canonical_uuid('post',$failed_child)==='');
 foreach(array('courses','topics','lesson','topic','reply') as $type) {
  if(!post_type_exists($type)) register_post_type($type,array('public'=>false,'hierarchical'=>true));
 }
 $course=owned_child_post(array('post_type'=>'courses','post_status'=>'draft','post_title'=>'Owned native course'));
 WPMMCC\Sync\Event_Recorder::run_in_ingestion_scope(static function() use($course) {
  R::import_payload(new WPMMCC\Adapters\Adapter_LMS(),$course,array('topics'=>array(
   array('title'=>'Owned native topic','lessons'=>array(array('title'=>'Owned native lesson',
    'post_type'=>'lesson','content'=>'Owned native lesson content'))))),array());
 });
 $topics=get_posts(array('post_type'=>'topics','post_parent'=>$course,'post_status'=>'any','numberposts'=>-1));
 $topic_id=$topics[0]->ID ?? 0; if($topic_id) $created[]=$topic_id;
 $lessons=get_posts(array('post_type'=>'lesson','post_parent'=>$topic_id,'post_status'=>'any','numberposts'=>-1));
 $lesson_id=$lessons[0]->ID ?? 0; if($lesson_id) $created[]=$lesson_id;
 owned_child_check('LMS native descendants get receiver identity in ingestion',$topic_id>0 && $lesson_id>0
  && get_post_meta($topic_id,'_wpmmcc_canonical_uuid',true)!==''
  && get_post_meta($lesson_id,'_wpmmcc_canonical_uuid',true)!=='');
 $forum=owned_child_post(array('post_type'=>'topic','post_status'=>'draft','post_title'=>'Owned native forum topic'));
 WPMMCC\Sync\Event_Recorder::run_in_ingestion_scope(static function() use($forum) {
  R::import_payload(new WPMMCC\Adapters\Adapter_Bbpress(),$forum,array('bbp_type'=>'topic','replies'=>array(
   array('title'=>'Owned native reply','content'=>'Owned native reply content'))),array());
 });
 $replies=get_posts(array('post_type'=>'reply','post_parent'=>$forum,'post_status'=>'any','numberposts'=>-1));
 $reply_id=$replies[0]->ID ?? 0; if($reply_id) $created[]=$reply_id;
 owned_child_check('bbPress native descendant gets receiver identity in ingestion',$reply_id>0
  && get_post_meta($reply_id,'_wpmmcc_canonical_uuid',true)!=='');

 $foreign=owned_child_post(array('post_type'=>'post','post_status'=>'draft','post_title'=>'Foreign original'));
 $foreign_categories=wp_get_object_terms($foreign,'category',array('fields'=>'ids'));
 $category=wp_insert_term('Owned child-policy category','category');
 if(is_wp_error($category)) throw new RuntimeException('owned category fixture failed');
 $term=$category['term_id'];
 $custom=new class($foreign,$term) implements I {
  private $foreign; private $term;
  public function __construct($foreign,$term) { $this->foreign=$foreign; $this->term=$term; }
  public function get_slug(): string { return 'owned-child-core'; }
  public function get_supported_post_types(): array { return array('product'); }
  public function is_plugin_active(): bool { return true; }
  public function export_custom_payload(int $id,$post): array { return array(); }
  public function get_translatable_fields(string $type): array { return array('post_title'); }
  public function import_custom_payload(int $id,array $payload,array $context=array()): bool {
   if($payload['mode']==='core') {
    wp_update_post(array('ID'=>$id,'post_title'=>'Selected native title','post_content'=>'Unselected native content'));
    wp_update_post(array('ID'=>$this->foreign,'post_title'=>'Foreign hijack','post_status'=>'publish'));
   } elseif($payload['mode']==='foreign-taxonomy') {
    wp_set_object_terms($this->foreign,array($this->term),'category');
   } elseif($payload['mode']==='root-remove-taxonomy') {
    wp_remove_object_terms($id,array($this->term),'category');
   } else {
    wp_set_object_terms($id,array($this->term),'category');
   }
   return true;
  }
 };
 $custom_context=array('delta_merge'=>true,'changed_fields'=>array('post_title'),'protected_fields'=>array('post_status'));
 R::import_payload($custom,$root,array('mode'=>'core'),$custom_context);
 owned_child_check('selected adapter core remains writable',get_post($root)->post_title==='Selected native title');
 owned_child_check('unselected adapter quoted core is preserved',get_post($root)->post_content===$root_content);
 owned_child_check('adapter cannot edit an unrelated post core',get_post($foreign)->post_title==='Foreign original'
  && get_post($foreign)->post_status==='draft');
 R::import_payload($custom,$root,array('mode'=>'foreign-taxonomy'),$custom_context);
 owned_child_check('adapter cannot edit an unrelated taxonomy',wp_get_object_terms($foreign,'category',array('fields'=>'ids'))===$foreign_categories);
 R::import_payload($custom,$root,array('mode'=>'root-taxonomy'),$custom_context);
 owned_child_check('unselected adapter taxonomy is preserved',wp_get_object_terms($root,'category',array('fields'=>'ids'))===array());
 R::import_payload($custom,$root,array('mode'=>'root-taxonomy'),array('delta_merge'=>true,
  'changed_fields'=>array('category'),'protected_fields'=>array('post_status')));
 owned_child_check('selected adapter taxonomy remains writable',wp_get_object_terms($root,'category',array('fields'=>'ids'))===array($term));
 R::import_payload($custom,$root,array('mode'=>'root-remove-taxonomy'),$custom_context);
 owned_child_check('unselected adapter taxonomy removal is blocked',wp_get_object_terms($root,'category',array('fields'=>'ids'))===array($term));
 wp_remove_object_terms($root,array($term),'category');
 wp_set_object_terms($root,array($term),'category');
 owned_child_check('adapter policy releases native term guard after import',wp_get_object_terms($root,'category',array('fields'=>'ids'))===array($term));
 wp_delete_term($term,'category');
} finally {
 foreach(array_unique($created) as $id) wp_delete_post($id,true);
 foreach(get_posts(array('post_type'=>'product_variation','post_parent'=>$root,'post_status'=>'any','numberposts'=>-1)) as $post)
  wp_delete_post($post->ID,true);
}
echo wp_json_encode($checks);
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    context = OWNED.load(args.context)
    site = context["sites"][1]
    info = json.loads(subprocess.check_output(["docker", "inspect", site["name"]], text=True))[0]
    settings = dict(value.split("=", 1) for value in info["Config"]["Env"])
    if (info["Config"]["Labels"].get(OWNED.LABEL) != context["owner"]
            or info["Name"] != "/" + site["name"]
            or settings["WORDPRESS_TABLE_PREFIX"] != site["prefix"]
            or not re.fullmatch(r"[a-f0-9]{64}", info["Id"])):
        raise ValueError("owned immutable identity mismatch")
    checks = json.loads(OWNED.wp(info["Id"], PHP))
    rows = [{"name": name, "passed": bool(value)} for name, value in checks.items()]
    for row in rows:
        print(("PASS " if row["passed"] else "FAIL ") + row["name"])
    report = {"kind": "owned-native-adapter-child-policy",
              "passed": sum(r["passed"] for r in rows),
              "failed": sum(not r["passed"] for r in rows), "checks": rows}
    args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(f"RESULT {report['passed']} passed, {report['failed']} failed")
    return bool(report["failed"])


if __name__ == "__main__":
    raise SystemExit(main())
