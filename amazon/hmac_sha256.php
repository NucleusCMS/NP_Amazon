 <?php
#####################################################################
#   HMAC-SHA256専用クラス
#       Copyright (c)ソフト屋 巣
#   http://softyasu.blog121.fc2.com/blog-entry-101.html
#####################################################################

//  sha256ライブラリ
require_once('sha256.inc.php');
   
//  参考：RFC2104 http://www.ipa.go.jp/security/rfc/RFC2104JA.html
class HMAC_SHA256 {

    var $ipadkey;   //innerパッドキー
    var $opadkey;   //outerパッドキー
    var $pack_form; //packフォーマット。ブロック長とフォーマット。
   
    //==================================================
    //  コンストラクタ
    //      $key HMACの鍵
    //==================================================
    function HMAC_SHA256($key)
    {
        $this->pack_form = "H64";   //SHA256専用。
        define("HASH_BLOCK_SIZE",64);    //ブロック長
       
        //  鍵のパディング。
        if(strlen($key) > HASH_BLOCK_SIZE) {
            $key = pack($this->pack_form,sha256($key));//大きい場合はハッシュ化。
        } elseif(strlen($key) < HASH_BLOCK_SIZE) {
            $key = str_pad($key,HASH_BLOCK_SIZE,chr(0));
        }
       
        //  inner/outerパッドキーの作成。
        $this->ipadkey = (substr($key,0,HASH_BLOCK_SIZE) ^ str_repeat(chr(0x36),HASH_BLOCK_SIZE));
        $this->opadkey = (substr($key,0,HASH_BLOCK_SIZE) ^ str_repeat(chr(0x5C),HASH_BLOCK_SIZE));
    }
   
    //==================================================
    //  HMAC関数
    //      $msg メッセージ
    //==================================================
    function hmac($msg)
    {
        //  innerハッシュ
        $hashin = sha256($this->ipadkey.$msg);
        //  innerハッシュにouterを合成してハッシュを求める。
        $hash = sha256($this->opadkey.pack($this->pack_form,$hashin));
        //  パックして持ち帰る。
        return pack($this->pack_form,$hash);
    }
}
?>