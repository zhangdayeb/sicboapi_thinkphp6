<?php


namespace app\model;


use think\Model;

class TableLangModel extends Model
{
    public $name = 'dianji_table_lang';

    public function getExplainAttr($value)
    {
        return html_entity_decode(str_replace('/topic/', config('ToConfig.app_update.image_url') . '/topic/', $value));
    }

    public static function table_explain($id)
    {
       return  self::where('table_id',$id)->select();
    }

    public static function table_explain_value($info)
    {
        $sel=   self::where('table_id',$info['id'])->column('lang_type,explain');
        if (empty($sel)) return [];
        $data ['zh']= $info['table_title'];
        foreach ($sel as $key=>$value){
            if ($value['lang_type'] == 'en-us') $value['lang_type']='en';
            if ($value['lang_type'] == 'zh-cn') $value['lang_type']='zh';
            if ($value['lang_type'] == 'jp') $value['lang_type']='jpn';
            $data[$value['lang_type']] = $value['explain'];
        }
        return $data;
    }

    public static function page_one($map)
    {
        return  self::where($map)->find();
    }

    public static function set_insert($map){
        return  self::insert($map);
    }

    public static function set_update($map){
        return  self::update($map);
    }
}