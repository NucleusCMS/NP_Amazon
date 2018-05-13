<?php
/*
  Todo: [保留中] PHP最低動作要件の変更(PHP5.2.0以上)をすることで添付のamazonフォルダを全削除できるので、コードを整理する。
 */

class NP_Amazon extends NucleusPlugin {
    const aws_version = '2011-08-01'; // https://docs.aws.amazon.com/ja_jp/AWSECommerceService/latest/DG/Versioning.html
    private $_active = FALSE;
    function getActive() { return $this->_active; }

    function getName() {
        return 'Amazon';
    }

    function getAuthor() {
        return 'Misc authors';
        //  nekhet,
    }

    function getURL() {
        return 'https://github.com/NucleusCMS/NP_Amazon';
        // 'http://nekhet.ddo.jp/item/1517'
    }

    function getVersion() {
        return '0.6.2';
    }

    function getDescription() {
        return 'Amazon Associate plugin.<br />Usage:<br />in Item &lt;%Amazon(asbn|imgsize|related|template)%&gt;<br />in Skin &lt;%Amazon(imgsize|list num|template)%&gt;';
    }

    function supportsFeature($feature) { return in_array ($feature, array ('SqlTablePrefix', 'SqlApi')); }

    function getTableList() { return array( sql_table('plugin_amazon') ); }
    function getEventList() { return array('PreItem'); }

    private function getCreateSQL() {
        $table = sql_table('plugin_amazon');
        $sql =<<<EOL
            CREATE TABLE IF NOT EXISTS $table (
                id           int(11)      NOT NULL auto_increment,
                blogid       int(11)      NOT NULL default '1',
                asbncode     VARCHAR(15)  NOT NULL default '0',
                title        tinytext,
                catalog      varchar(50)           default NULL,
                media        varchar(50)           default NULL,
                author       varchar(250)          default NULL,
                manufacturer varchar(250)          default NULL,
                listprice    varchar(50)           default NULL,
                ourprice     varchar(50)           default NULL,
                point        varchar(50)           default NULL,
                releasedate  varchar(50)           default NULL,
                availability varchar(50)           default NULL,
                amazonrate   float                 default '0',
                myrate       float                 default '0',
                extradata    varchar(255)          default NULL,
                used         enum('yes', 'no')     default 'no',
                userdata     varchar(250)          default NULL,
                date         varchar(50)           default NULL,
                adddate      varchar(50)           default NULL,
                similar      text                  default NULL,
                imgsize      varchar(60)           default NULL,
                img          enum('yes', 'no')     default 'no',
                detailpageurl  text       NOT NULL default '',
                smallimageurl  text       NOT NULL default '',
                mediumimageurl text       NOT NULL default '',
                largeimageurl  text       NOT NULL default '',
                PRIMARY KEY (id),
                UNIQUE(asbncode)
            ) ENGINE=MyISAM
EOL;
        return $sql;
    }

    private function updateTable() {
        if (!$this->existTable())
            return ; // table not exist

        if(!$this->existColumn('point')) {
            $sql = "ALTER TABLE ".sql_table('plugin_amazon');
            $sql .= " ADD point varchar(50) default NULL";
            $sql .= " AFTER ourprice";
            sql_query($sql);
        }

        if(!$this->existColumn('releasedate')) {
            $sql = "ALTER TABLE ".sql_table('plugin_amazon');
            $sql .= " ADD releasedate varchar(50) default NULL";
            $sql .= " AFTER point";
            sql_query($sql);
        }

        if(!$this->existColumn('userdata')) {
            $sql = "ALTER TABLE ".sql_table('plugin_amazon');
            $sql .= " ADD userdata varchar(250) default NULL";
            $sql .= " AFTER used";
            sql_query($sql);
        }

        if(!$this->existColumn('imgsize')) {
            $sql = "ALTER TABLE ".sql_table('plugin_amazon');
            $sql .= " ADD imgsize varchar(60) default NULL";
            $sql .= " AFTER similar";
            sql_query($sql);
        }

        if(!$this->existColumn('extradata')) {
            $sql = "ALTER TABLE ".sql_table('plugin_amazon');
            $sql .= " ADD extradata varchar(255) default NULL";
            $sql .= " AFTER myrate";
            sql_query($sql);
        }

        $sql = "ALTER TABLE ".sql_table('plugin_amazon');
        $sql .= " CHANGE asbncode asbncode varchar(15)";
        sql_query($sql);

        // text not null default ''
        $cols = array('detailpageurl', 'smallimageurl', 'mediumimageurl', 'largeimageurl');
        foreach($cols as $colname) {
            if(!$this->existColumn($colname)) {
                $sql = "ALTER TABLE ".sql_table('plugin_amazon');
                $sql .= " ADD $colname text NOT NULL default ''";
                sql_query($sql);
            }
        }

        // 値のない古いデータを更新リストへ送る
        $where = sprintf(' WHERE date >= %d', time() - $this->flashtime);
        $where .= " AND ( detailpageurl = '' OR ( ";
        $where .= " smallimageurl = '' AND mediumimageurl = '' AND largeimageurl = ''";
        $where .= ' ) )';
        $query = sprintf('UPDATE `%s` SET date = %d', sql_table('plugin_amazon'), time() - $this->flashtime -1) . $where;
        sql_query($query);
    }

    protected function existTable() {
        $tablename = sql_table('plugin_amazon');
        $sql = sprintf("SHOW TABLES LIKE '%s'", $tablename);
        $res = sql_query($sql);
        if(!$res || !sql_fetch_object($res)) {
            return false; // table not exist
        }
        return true;
    }

    protected function existColumn($column_name) {
        $tablename = sql_table('plugin_amazon');
        $sql = sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'", $tablename , $column_name);
        $res = sql_query($sql);
        if(!$res || !sql_fetch_object($res)) {
            return false; // column not exist
        }
        return true;
    }

    private function checkUpdateTable() {
        if($this->existTable()) {
            if(!$this->existColumn('largeimageurl'))
                $this->updateTable();
        }
    }

    function install() {
        global $DIR_MEDIA;
        $this->createOption("atoken", "Amazon Access Key ID:", "text", "");
        $this->createOption("secret_key", "Secret Access Key:", "text", "");
        $this->createOption("aid", "Amazon Associates ID:", "text", "");
        $this->createOption("flashtime", "情報キャッシュ期間（Amazon Webサービス使用許諾条件に従うこと）", "select", "3600","1時間|3600|24時間|86400|1週間|604800|3カ月|7776000");

        $this->createOption("encode", "エンコード選択", "select", "UTF-8","UTF-8|UTF-8|EUC-JP|EUC-JP");
//        $this->createOption("xmlphp", "xml.phpのパス（NP_Amazon.phpと同じディレクトリに保存した場合は空白）", "text", "");
        $this->createOption("del_uninstall", "Delete tables on uninstall?", "yesno", "no");

        // Database : create custum plugin table
        $sql = $this->getCreateSQL();
        sql_query($sql);

        $this->updateTable();

        if(!is_dir($DIR_MEDIA."aws")) {
            mkdir($DIR_MEDIA."aws", 0755);
        }
    }

    function uninstall() {
        if($this->getOption('del_uninstall') == "yes") {
            sql_query("DROP table ".sql_table('plugin_amazon'));
        }
		$this->deleteOption('del_uninstall');
    }

    protected function isValidAccount() {
        $invalid = empty($this->aid) || empty($this->atoken) || empty($this->secret_key);
        return !$invalid;
    }

    function init() {
        global $CONF;
        $this->flashtime = $this->getOption("flashtime");
        $this->encode = $this->getOption("encode");

        $this->checkUpdateTable();

        $this->aid        = trim($this->getOption("aid"));
        $this->atoken     = trim($this->getOption("atoken"));
        $this->secret_key = trim($this->getOption("secret_key"));

        $this->_active = TRUE;
        if (!$this->isValidAccount()) {
            global $member;
            $this->_active = FALSE;
            if ($member->isLoggedIn()) {
                $msg = 'NP_Amazonのアカウント設定がされていません';
                if (class_exists('SYSTEMLOG'))
                    SYSTEMLOG::addUnique('error', 'ERROR', $msg);
                elseif (class_exists('ACTIONLOG') && method_exists('ACTIONLOG', 'addUnique'))
                    ACTIONLOG::addUnique(0, $msg);
            }
            // Todo: Dispaly ADMIN message panel. // if ($CONF['UsingAdminArea'] && $member->isLoggedIn() && $member->isAdmin())
        }
    }

    // doSkinVar($skinType, $imgsize, $num, $template)
    function doSkinVar($skinType) {
        global $CONF;
        list( , $imgsize, $num, $template) = func_get_args();
        if(!$imgsize) {
            $imgsize = "m";
        }

        if(!$num) {
            $num = 3;
        }

        if (!$this->isValidAccount()) {
            global $member;
            if (!$member->isLoggedIn() || !$member->isAdmin())
               return "<!-- NP_Amazonの設定が未入力です。 -->";
            return sprintf('<a href="%sindex.php?action=pluginoptions&amp;plugid=%d">%s</a>',
                    $CONF['AdminURL'], $this->getID() ,"NP_Amazonの設定が未入力です。");
        }

        $sql = "SELECT * FROM ".sql_table('plugin_amazon')." ORDER BY adddate DESC LIMIT $num";
        $result = sql_query($sql);
        while($product = sql_fetch_assoc($result)) {
            $product['date'] = date('n月j日 G:i', $product['date']);
            $this->getImages($product, $imgsize);
            if($product['catalog'] == "Book" or $product['catalog'] == "Music" or $product['catalog'] == "DVD") {
                $product['imgstyle'] = "class='imgshadow'";
            }

            if(strstr($product['asbncode'], 'none') == FALSE) {
                $showurl = '<a href="http://www.amazon.co.jp/exec/obidos/ASIN/'.$product['asbncode'].'/'.$this->aid.'" target="_blank">';
                $product['showimg'] = $showurl.'<img src="'.$product['imgfile'].'" '.$product['attr'].' border="0" '.$product['imgstyle'].' alt="'.$product['title'].'" /></a>';
                $product['showtitle'] = $showurl.$product['title'].'</a>';
                $product['button'] = $this->add_cart($product['asbncode']);
            }else{
                $product['showimg'] = '<img src="'.$product['imgfile'].'" '.$product['attr'].' border="0" '.$product['imgstyle'].' alt="'.$product['title'].'" />';
                $product['showtitle'] = $product['title'];
            }
            $product['amazonratemark'] = $this->mkRating($product['amazonrate']);
            $product['myratemark'] = $this->mkRating($product['myrate']);
            $product['edit'] = $this->canEdit()?'<a href="'.$CONF['ActionURL'] . '?action=plugin&amp;name=Amazon&amp;type=edit&amp;asbncode='.$product['asbncode'].'" target="_blank">edit</a>':'';
            $this->getTemplate($template);
            $out = TEMPLATE::fill($this->template_asbn,$product);;
            echo $out;
        }
    }

    function event_PreItem($data) {
        $preg_expr = "#<\%amazon\((.*?)\)%\>#i";
        $this->currentItem = &$data["item"];

//change ma cause array problem
		$param = array(&$this, 'convertAsbn');
		$this->currentItem->body = preg_replace_callback($preg_expr, $param, $this->currentItem->body);
		$this->currentItem->more = preg_replace_callback($preg_expr, $param, $this->currentItem->more);
/*        $this->currentItem->body = preg_replace_callback($preg_expr, array(&$this, 'convertAsbn'), $this->currentItem->body);
        $this->currentItem->more = preg_replace_callback($preg_expr, array(&$this, 'convertAsbn'), $this->currentItem->more);*/



    }

    function convertAsbn($matches) {
        global $CONF;
        if (!$this->isValidAccount()) {
            global $member;
            if (!$member->isLoggedIn() || !$member->isAdmin())
               return "<!-- NP_Amazonの設定が未入力です。 -->";
            return sprintf('<a href="%sindex.php?action=pluginoptions&amp;plugid=%d">%s</a>',
                    $CONF['AdminURL'], $this->getID() ,"NP_Amazonの設定が未入力です。");
        }

        $late = 46;// 更新時間を何分前にするか
        list($asbncode, $imgsize, $similarnum, $template) = explode("|", $matches[1]);
        if(!$similarnum) {
            $similarnum = 0;
        }elseif($similarnum > 5) {
            $similarnum = 5;
        }
        $asbncode = str_replace(array("-","ISBN"),"",$asbncode);
        if($asbncode == "none") {
            $itemid = $this->currentItem->itemid;
            $blogid = getBlogIDFromItemID($itemid);
            $asbncode = "none".$blogid.$itemid;
        }

        $sql = "SELECT * FROM ".sql_table('plugin_amazon')." WHERE asbncode='".$asbncode."' LIMIT 1";
        //$result = sql_query($sql);
		$result = sql_query($sql);

        if(sql_num_rows($result) > 0) {
            $product = sql_fetch_assoc($result);
//change ma cause by mktime()
//			if($product['date'] < mktime() - $this->flashtime) {
			if($product['date'] < time() - $this->flashtime) {
				$this->updateData($product);
			}
        } else {
            $product['asbncode'] = $asbncode;
            $this->newData($product);
        }
        $product['date'] = date('n月j日 G:i', $product['date']);
        $this->getImages($product, $imgsize);

        if($product['catalog'] == "Book" or $product['catalog'] == "Music" or $product['catalog'] == "DVD") {
            $product['imgstyle'] = "class='imgshadow'";
        }

        if(strstr($product['asbncode'], "none") == FALSE) {
            if (empty($product['detailpageurl']))
                $url = sprintf('http://www.amazon.co.jp/exec/obidos/ASIN/%s/%s', $product['asbncode'], $this->aid);
            else
                $url = $product['detailpageurl'];
            $showurl = sprintf('<a href="%s" target="_blank">', $url);

            $product['showimg'] = $showurl.'<img src="'.$product['imgfile'].'" '.$product['attr'].' border="0" '.$product['imgstyle'].' alt="'.$product['title'].'" /></a>';
            $product['showtitle'] = $showurl.$product['title'].'</a>';
            $product['button'] = $this->add_cart($asbncode);
        }else{
            $product['showimg'] = '<img src="'.$product['imgfile'].'" '.$product['attr'].' border="0" '.$product['imgstyle'].' alt="'.$product['title'].'" />';
            $product['showtitle'] = $product['title'];
        }

        $product['similars'] = $this->convertSimilar($product['similar'], $similarnum);
        if($product['similars'] != "") {
            $product['similars'] = 'Amazon関連商品<br />'.$product['similars'];
        }

        $product['amazonratemark'] = $this->mkRating($product['amazonrate']);
        $product['myratemark'] = $this->mkRating($product['myrate']);

        if($product['point'] == "") {
            $product['point'] = "なし";
        }else{
            $product['point'] = $product['point']."pt";
        }

        if($product['ourprice'] == "") {
            $product['ourprice'] = "在庫切れ";
        }

        if($product['availability'] == "") {
            $product['availability'] = "在庫切れ";
        }

        $product['edit'] = $this->canEdit()?'<a href="'.$CONF['ActionURL'] . '?action=plugin&amp;name=Amazon&amp;type=edit&amp;asbncode='.$product['asbncode'].'" target="_blank">edit</a>':'';

        $this->getTemplate($template);
        $out = TEMPLATE::fill($this->template_asbn,$product);;
        return $out;
    }

    function newData($product) {
        $this->getAmazonData($product, $mode = "new");
        if (empty($product['detailpageurl']))
            return ;
        $blogid = getBlogIDFromItemID($this->currentItem->itemid);
//change ma cause by mktim
//        $product['date'] = mktime();
		$product['date'] = time();

        if($this->encode == "EUC-JP") {
            mb_convert_variables("EUC-JP", "UTF-8", $product);
        }
        $sql = 'INSERT INTO ' . sql_table('plugin_amazon')
            . " (blogid, asbncode, title, catalog, media, author, manufacturer,"
            . " listprice, ourprice, point, releasedate, availability,"
            . " amazonrate, myrate, similar, imgsize,"
            . " detailpageurl,"
            . " smallimageurl, mediumimageurl, largeimageurl,"
            . " date, adddate)"
        . "VALUES ('".$blogid."',
        '".sql_real_escape_string($product['asbncode'])."',
        '".sql_real_escape_string($product['title'])."',
        '".sql_real_escape_string($product['catalog'])."',
        '".sql_real_escape_string($product['media'])."',
        '".sql_real_escape_string($product['author'])."',
        '".sql_real_escape_string($product['manufacturer'])."',
        '".sql_real_escape_string($product['listprice'])."',
        '".sql_real_escape_string($product['ourprice'])."',
        '".sql_real_escape_string($product['point'])."',
        '".sql_real_escape_string($product['releasedate'])."',
        '".sql_real_escape_string($product['availability'])."',
		'".sql_real_escape_string(floatval($product['amazonrate']))."',
		'".sql_real_escape_string(floatval($product['myrate']))."',
        '".sql_real_escape_string($product['similar'])."',
		'".sql_real_escape_string($product['imgsize'])."',
		'".sql_real_escape_string($product['detailpageurl'])."',
		'".sql_real_escape_string($product['smallimageurl'])."',
		'".sql_real_escape_string($product['mediumimageurl'])."',
		'".sql_real_escape_string($product['largeimageurl'])."',
        ".time().",".time().")";
		//mktime()
        //$res = @sql_query($sql);
		$res = @sql_query($sql);
        if(!$res)
            return 'Could not save data, possibly because of a double entry: ' . sql_error();
    }

    function updateData($product) {
        $this->getAmazonData($product, $mode = "update");
        if (empty($product['detailpageurl']))
            return ;
        if($this->encode == "EUC-JP") {
            mb_convert_variables("EUC-JP", "UTF-8", $product);
        }
        
//		$product['date'] = mktime();
		$product['date'] = time();


        $sql = 'UPDATE '.sql_table('plugin_amazon')
            . " SET     ourprice='". sql_real_escape_string($product['ourprice']) . "',"
            . "     point='". sql_real_escape_string($product['point']) . "',"
            . "     availability='" . sql_real_escape_string($product['availability']) . "',"
            . "     similar='". sql_real_escape_string($product['similar']) . "',"
            . "     imgsize='". sql_real_escape_string($product['imgsize']) . "',"
            . "     date='" . sql_real_escape_string($product['date'])  . "',"
            . "     detailpageurl='" . sql_real_escape_string($product['detailpageurl'])  . "',"
            . "     smallimageurl='" . sql_real_escape_string($product['smallimageurl'])  . "',"
            . "     mediumimageurl='" . sql_real_escape_string($product['mediumimageurl'])  . "',"
            . "     largeimageurl='" . sql_real_escape_string($product['largeimageurl'])  . "'"
            . " WHERE asbncode='" . sql_real_escape_string($product['asbncode'])."'";
			//. " SET amazonrate='" . sql_real_escape_string($product['amazonrate']) . "',"
//       sql_query($sql);*/
		sql_query($sql);
    }

    public function makeAmazonRequestURLWithSignature($params) {
		$baseurl = "http://ecs.amazonaws.jp/onca/xml?";
		$params["Service"]        = "AWSECommerceService";
		$params["Version"]        = self::aws_version;
		$params["AWSAccessKeyId"] = $this->atoken;
		$params["AssociateTag"]   = $this->aid;
		$params["Timestamp"]      = gmdate("Y-m-d\TH:i:s\Z");

		ksort($params);
		$query = array();
		foreach ($params as $param=>$value) {
			$param = str_replace("%7E", "~", rawurlencode($param));
			$value = str_replace("%7E", "~", rawurlencode($value));
			$query[] = $param."=".$value;
		}

		$query = implode("&", $query);

		$string2sign = "GET\necs.amazonaws.jp\n/onca/xml\n".$query;
		if (function_exists("hash_hmac")) {
			$signature = base64_encode(hash_hmac("sha256", $string2sign, $this->secret_key, true));
		} else {
			require_once('amazon/hmac_sha256.php');
			$hmac_sha256 = new HMAC_SHA256($this->secret_key);
			$signature = base64_encode($hmac_sha256->hmac($string2sign));
		}
		$signature = str_replace("%7E", "~", rawurlencode($signature));
		return $baseurl.$query."&Signature=".$signature;
	}

    function getAmazonData(&$product, $mode) {
        if (!$this->getActive())
            return;

        // https://docs.aws.amazon.com/AWSECommerceService/latest/DG/ItemLookup.html
        $params = array();
		$params["ItemId"] = $product['asbncode'];
		$params["ItemPage"] = "1";
		$params["Operation"] = "ItemLookup";
		$params["ResponseGroup"] = "Small,ItemAttributes,OfferFull,Images,Similarities,Reviews";

		$request = $this->makeAmazonRequestURLWithSignature($params);
		$xml = @file_get_contents($request);
        if ($xml === FALSE) {
            // APIの制限： 1秒間に１回までの制限がある
            usleep(500000); // 0.5秒間待機します(500*1000マイクロ秒)
            $request = $this->makeAmazonRequestURLWithSignature($params);
            $xml = @file_get_contents($request);
            if ($xml === FALSE) {
                return; // 失敗したので取得をあきらめて関数を終了する
            }
        }

        if (function_exists('json_encode') && class_exists('SimpleXMLElement')) {
            $obj = new SimpleXMLElement($xml);
            $obj_item = $obj->Items->Item;
            $json = json_encode($obj_item);
            $ews_item = json_decode($json,TRUE);
            unset($obj, $obj_item);
        } else {
/*
        if($this->getOption("xmlphp") == "") {
            $this->xmlphp = "xml.php";
        }else{
            $this->xmlphp = $this->getOption("xmlphp");
        }
*/
            require_once('amazon/xml.php');
            $ews = XML_unserialize($xml);
            $ews_item = &$ews['ItemLookupResponse']['Items']['Item'];
            unset($ews);
        }

        if (empty($ews_item))
            return ;

        if (!empty($ews_item['DetailPageURL']))
            $product['detailpageurl'] = (string) $ews_item['DetailPageURL'];
        foreach(array('SmallImage', 'MediumImage', 'LargeImage') as $key0) {
            $tmp_key = strtolower($key0) . 'url';
            if (empty($ews_item[$key0]['URL']))
                $product[$tmp_key] = '';
            else
                $product[$tmp_key] = (string) $ews_item[$key0]['URL'];
        }

        if($mode == "new") {
            $product['title'] = $ews_item['ItemAttributes']['Title'];
            $product['catalog'] = $ews_item['ItemAttributes']['ProductGroup'];
            $product['media'] = $ews_item['ItemAttributes']['Binding'];
            $product['manufacturer'] = $ews_item['ItemAttributes']['Manufacturer'];
            $product['releasedate'] = $ews_item['ItemAttributes']['ReleaseDate'];
            $product['listprice'] = $ews_item['ItemAttributes']['ListPrice']['FormattedPrice'];

            if(is_array($ews_item['ItemAttributes']['Author'])) {
                $i = 0;
                while($ews_item['ItemAttributes']['Author'][$i] != "") {
                    $author[] = $ews_item['ItemAttributes']['Author'][$i];
                    $i = $i + 1;
                }
                if(is_array($author)) {
                    $product['author'] = implode("、", $author);
                }
            } elseif($ews_item['ItemAttributes']['Author'] != "") {
                $product['author'] = $ews_item['ItemAttributes']['Author'];
            }

            if(is_array($ews_item['ItemAttributes']['Artist'])) {
                $i = 0;
                while($ews_item['ItemAttributes']['Artist'][$i] != "") {
                    $author[] = $ews_item['ItemAttributes']['Artist'][$i];
                    $i = $i + 1;
                }
                if(is_array($author)) {
                    $product['author'] = implode("、", $author);
                }
            } elseif($ews_item['ItemAttributes']['Artist'] != "") {
                $product['author'] = $ews_item['ItemAttributes']['Artist'];
            }
        }

        $product['ourprice'] = $ews_item['Offers']['Offer']['OfferListing']['Price']['FormattedPrice'];

        // 2010/11/08 に CustomerReviews はリンクのみ返すように仕様変更された。
        //   CustomerReviews/IFrameURL , CustomerReviews/HasReviews
        $product['amazonrate'] = 0; //$ews_item['CustomerReviews']['AverageRating'];

        $product['availability'] = $ews_item['Offers']['Offer']['OfferListing']['Availability'];
        $product['point'] = $ews_item['Offers']['Offer']['LoyaltyPoints']['TypicalRedemptionValue']['Amount'];

        $i = 0;
        while($ews_item['SimilarProducts']['SimilarProduct'][$i]['Title'] != "") {
            if($i == 0) {
                $product['similar'] = "";
            }else{
                $product['similar'] .= "|";
            }
            $product['similar'] .= $ews_item['SimilarProducts']['SimilarProduct'][$i]['ASIN'] . ":" . $ews_item['SimilarProducts']['SimilarProduct'][$i]['Title'];
            $i = $i + 1;
        }

        $size = array("Small","Medium","Large");
        $product['imgsize'] = "";
        foreach($size as $tmp) {
            $imgsize = $tmp."Image";
            if($tmp != "Small") {
                $product['imgsize'] .= ",";
            }
            $product['imgsize'] .= $ews_item[$imgsize]['Width'] . "," . $ews_item[$imgsize]['Height'];
        }
    }

    function convertSimilar($spdata, $num) {
        if($spdata != "") {
            $max = $num;
            $sp = explode('|', $spdata);
            if(count($sp) < $num) {
                $max = count($sp);
            }

            $i = 0;
            while($i < $max) {
                list($asbncode, $title) = explode(":", $sp[$i]);
                $similar .= '・<a href="http://www.amazon.co.jp/exec/obidos/ASIN/'.$asbncode.'/'.$this->aid.'" target="_blank">'.$title.'</a><br />';
                $i = $i + 1;
            }
        }
        return $similar;
    }

    function getImages(&$product, $size) {
        global $CONF, $DIR_MEDIA;
        $amazonurl = 'http://images.amazon.com/images/P/';
        $result = "no";
        $tmpsize = explode(",", $product['imgsize']);

        $image_key = '';

        switch($size) {
            case 's':
                $img = "_SCTHUMBZZZ_.jpg";
                $width = $tmpsize[0];
                $height = $tmpsize[1];
				$noimgsize = 60;
                $image_key = 'smallimageurl';
                break;
            case 'm':
                $img = "_SCMZZZZZZZ_.jpg";
                $width = $tmpsize[2];
                $height = $tmpsize[3];
				$noimgsize = 120;
                $image_key = 'mediumimageurl';
                break;
            case 'l':
/*////////change ma
				$img = "_SCLZZZZZZZ_.jpg";
				$width = $tmpsize[4];
				$height = $tmpsize[5];*/
				$img = "_SCLZZZZZZZ_AA380_.jpg";
				$width = 380;
				$height = 380;
				$noimgsize = 180;
                $image_key = 'largeimageurl';
                break;
            default:
                $img = "_SCMZZZZZZZ_.jpg";
                $width = $tmpsize[2];
                $height = $tmpsize[3];
				$noimgsize = 120;
                $image_key = 'mediumimageurl';
                break;
        }

//        $noimg = "http://images-jp.amazon.com/images/G/09/x-locale/detail/thumb-no-image.gif";
		$noimg = 'http://images-jp.amazon.com/images/G/09/nav2/dp/no-image-no-ciu._AA'. $noimgsize .'_.gif';

        $imgfile = $product['asbncode'] .'.09.'.$img;

        if($width != "") {
            $product['imgfile'] = $amazonurl. $imgfile;
            $product['attr'] = 'width="'.$width.'" height="'.$height.'"';
            $result = "yes";
            if (!empty($image_key) && !empty($product[$image_key])) {
                $product['imgfile'] = $product[$image_key];
            }
        } else {
 //           $product['attr'] = 'width="50" height="60"';
            $product['imgfile'] = $noimg;
        }
    }

	function mkRating($rate) {
		if(empty($rate) || $rate == 0 || $rate == "") {
			$rating = '0-0';
		} elseif($rate >= 0 && $rate < 1) {
			$rating = '0-0';
		} elseif($rate >= 1 && $rate < 1.5) {
			$rating = '1-0';
		} elseif($rate >= 1.5 && $rate < 2) {
			$rating = '1-5';
		} elseif($rate >= 2 && $rate < 2.5) {
			$rating = '2-0';
		} elseif($rate >= 2.5 && $rate < 3) {
			$rating = '2-5';
		} elseif($rate >= 3 && $rate < 3.5) {
			$rating = '3-0';
		} elseif($rate >= 3.5 && $rate < 4) {
			$rating = '3-5';
		} elseif($rate >= 4 && $rate < 4.5) {
			$rating = '4-0';
		} elseif($rate >= 4.5 && $rate < 5) {
			$rating = '4-5';
		} elseif($rate >= 5) {
			$rating = '5-0';
		}
		return 'http://g-images.amazon.com/images/G/01/detail/stars-' .$rating. '.gif"';
	}

    function add_cart($asin) {
        $this->button =  <<<FORM
<form method="POST" action="http://www.amazon.co.jp/o/dt/assoc/handle-buy-box=$asin" target="_blank">
    <input type="hidden" name="asin.$asin" value="1">
    <input type="hidden" name="tag-value" value="$this->aid">
    <input type="hidden" name="tag_value" value="$this->aid">
    <input type="hidden" name="dev-tag-value" value="$this->atoken">
    <input type="submit" name="submit.add-to-cart" value="Amazonで購入">
</form>
FORM;
//  <input type="image" name="submit.add-to-cart" value="Amazonで購入" src="image filename" alt="Amazonで購入">
        return $this->button;
    }

    function canEdit() {
        global $member, $manager;
        if (!$member->isLoggedIn()) return 0;

            return $member->isAdmin();
    }

    function doAction($type) {
        global $CONF;
        $asbncode = requestVar('asbncode');
        switch($type) {
            case 'edit':
                if(!$this->canEdit()) {
                    return 'You\'re not allowed to edit this data';
                }
                $sql = "SELECT * FROM ".sql_table('plugin_amazon')." WHERE asbncode='".$asbncode."' LIMIT 1";
                //$result = sql_query($sql);
				$result = sql_query($sql);
                $row = sql_fetch_object($result);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=<?php echo $this->encode ?>" />
<title>Data edit page</title>
<link rel="stylesheet" type="text/css" href="<?php echo $CONF['AdminURL']?>styles/bookmarklet.css" />
</head>
<body>

<h1>Data edit page</h1>
<form method="post" action="<?php echo $CONF['ActionURL'] ?>"><div>
<input type="hidden" name="asbncode" value="<?php echo $asbncode?>" />
<input type="hidden" name="action" value="plugin" />
<input type="hidden" name="name" value="Amazon" />
<input type="hidden" name="type" value="update" />
<p>
<a href="http://www.amazon.co.jp/exec/obidos/ASIN/<?php echo hsc($row->asbncode)?>/" target="_blank">go to Amazon page</a>
</p>

<table>
<tr>
<td>ASBN</td>
<td><?php echo hsc($row->asbncode)?></td>
</tr><tr>
<td>Title</td>
<td><input type="text" value="<?php echo hsc($row->title)?>" name="title" size="80" /></td>
</tr><tr>
<td>Author</td>
<td><input type="text" value="<?php echo hsc($row->author)?>" name="author" size="80" /></td>
</tr><tr>
<td>Manufacturer</td>
<td><input type="text" value="<?php echo hsc($row->manufacturer)?>" name="manufacturer" size="30" /></td>
</tr><tr>
<td>My Rate</td>
<td><input type="text" value="<?php echo hsc($row->myrate)?>" name="myrate" size="1" /></td>
</tr><tr>
<td>User Data</td>
<td><input type="text" value="<?php echo hsc($row->userdata)?>" name="userdata" size="80" /></td>
</tr><tr>
<td>used</td>
<td><select name="used" />
<?php
if(hsc($row->used) == "yes") {
    echo "<option value='yes' selected>yes</option>";
    echo "<option value='no' >no</option>";
}else{
    echo "<option value='yes' >yes</option>";
    echo "<option value='no' selected>no</option>";
}
?>
</select></td>
</tr><tr>
<td>img</td>
<td><select name="img" />
<?php
if(hsc($row->img) == "yes") {
    echo "<option value='yes' selected>yes</option>";
    echo "<option value='no' >no</option>";
}else{
    echo "<option value='yes' >yes</option>";
    echo "<option value='no' selected>no</option>";
}
?>
</select></td>
</tr><tr>
<td> </td>
<td><input type="submit" value="update" />
</tr></table>
</div></form>
<form method="post" action="<?php echo $CONF['ActionURL'] ?>"><div>
<input type="hidden" name="asbncode" value="<?php echo $asbncode?>" />
<input type="hidden" name="action" value="plugin" />
<input type="hidden" name="name" value="Amazon" />
<input type="hidden" name="type" value="delete" />
<input type="submit" value="Delete this data" />
</form>
* After reflesh item page, reget Amazon data.
<p><a href="" onclick="window.close();">Close this windows</a></p>
</body>
</html>
<?php
                break;
            case 'update':
                if(!$this->canEdit()) {
                    return 'You\'re not allowed to edit this data';
                }
                $asbncode = requestVar('asbncode');
                $title = requestVar('title');
                $author = requestVar('author');
                $manufacturer = requestVar('manufacturer');
                $myrate = requestVar('myrate');
                $userdata = requestVar('userdata');
                $used = requestVar('used');
                $img = requestVar('img');
                $sql = 'UPDATE '.sql_table('plugin_amazon')
                    . " SET title='" . sql_real_escape_string($title) . "',"
                    . "     author='". sql_real_escape_string($author) . "',"
                    . "     manufacturer='" . sql_real_escape_string($manufacturer) . "',"
                    . "     myrate='" . floatval(sql_real_escape_string($myrate)) . "',"
                    . "     userdata='" . sql_real_escape_string($userdata) . "',"
                    . "     used='" . sql_real_escape_string($used) . "',"
                    . "     img='" . sql_real_escape_string($img)  . "'"
                    . " WHERE asbncode='" . $asbncode."'";
                //$res = sql_query($sql);
				$res = sql_query($sql);
                header('Location: ' . $CONF['ActionURL'] . '?action=plugin&name=Amazon&type=edit&asbncode='.$asbncode);
                break;
            case 'delete':
                $asbncode = requestVar('asbncode');
                if(!$this->canEdit()) {
                    return 'You\'re not allowed to edit this data';
                }
                $sql = 'DELETE FROM ' . sql_table('plugin_amazon') . " WHERE asbncode='" . $asbncode."'";
                //$res = sql_query($sql);
				$res = sql_query($sql);
        if(!$res){
            echo 'Could not save data, possibly because of a double entry: ' . sql_error();}
                echo '<p>* After reflesh item page, reget Amazon data.<br /><a href="" onclick="window.close();">Close this windows</a></p>';
                break;
            default:
                break;
        }
    }

    function getTemplate($template) {
        $this->template_asbn = "";
        switch($template) {
////////change ma
			case 'ma1': //080607cinema
				
				$this->template_asbn .= '<%showimg%>';
				$this->template_asbn .= '<%edit%>';
				
				break;			
			case 'ma': //050404Nuc-test
				$this->template_asbn .= '<ul><%showimg%>';
				$this->template_asbn .= '<li><%showtitle%></li>';
				$this->template_asbn .= '<li>auth:<%author%></li>';
				$this->template_asbn .= '<li>manu:<%manufacturer%></li>';
				$this->template_asbn .= '<li>rele:<%releasedate%></li>';
				$this->template_asbn .= '<li>medi:<%media%></li>';
				$this->template_asbn .= '<li><%edit%></li></ul>';
				break;
			case 'ma2': //050402Nuc-test
				$this->template_asbn .= '<div style="float:left;width:381px;">';
				$this->template_asbn .= '<%showimg%>';
				$this->template_asbn .= '</div>';
				$this->template_asbn .= '<div class="asbn">';
				$this->template_asbn .= '<br />';
				$this->template_asbn .= '製造:<%manufacturer%><br />';
				$this->template_asbn .= '発売日:<%releasedate%><br />';
				$this->template_asbn .= '<img src="<%myratemark%>" title="<%myrate%>" alt="<%myrate%>" />&nbsp;（おすすめ度）<br />';
				$this->template_asbn .= '<%edit%></div>';
				$this->template_asbn .= '<div class="similars">';
				$this->template_asbn .= '<%similars%>';
				$this->template_asbn .= '</div>';
				break;
//////////
            case 'simple': //sample template. simple view
                $this->template_asbn = '<div style="float:left;width:381px;">';
                $this->template_asbn .= '<%showimg%>';
                $this->template_asbn .= '</div>';
                $this->template_asbn .= '<div class="asbn">';
                $this->template_asbn .= '<%showtitle%>';
                $this->template_asbn .= '<br />';
                $this->template_asbn .= '<%author%><br />';
                $this->template_asbn .= '<%button%><%edit%>';
                $this->template_asbn .= '</div><br style="clear:both;"/>';
                break;
            case 'list': //sample template. list view
                $this->template_asbn = '<%showimg%><br />';
                $this->template_asbn .= '<%showtitle%>';
                $this->template_asbn .= '<br />';
                $this->template_asbn .= '<%author%><br />';
                $this->template_asbn .= '<%button%><%edit%>';
                $this->template_asbn .= '<br style="clear:both;"/><br />';
                break;
			default: //default template
				$this->template_asbn = '<p style="float:left;width:381px;">';
				$this->template_asbn .= '<%showimg%>';
		        $this->template_asbn .= '<br /><%edit%>';
				$this->template_asbn .= '</p>';
				$this->template_asbn .= '<div class="asbn">';
				$this->template_asbn .= '<%showtitle%>';
				$this->template_asbn .= '<br />';
				$this->template_asbn .= '<%author%><br />';
				$this->template_asbn .= '<%manufacturer%><br />';
				$this->template_asbn .= '<%listprice%>&nbsp;（定価）<br />';
				$this->template_asbn .= '<%ourprice%>&nbsp;（Amazon価格）<br />';
				$this->template_asbn .= '<%point%>&nbsp;（Amazonポイント）<br />';
				$this->template_asbn .= '<img src="<%myratemark%>" title="<%myrate%>" alt="<%myrate%>" />&nbsp;（私のおすすめ度）<br />';
//				$this->template_asbn .= '<img src="<%amazonratemark%>" alt="<%amazonrate%>" title="<%amazonrate%>" />&nbsp;（Amazonおすすめ度）<br />';
				$this->template_asbn .= '<%media%><br />';
				$this->template_asbn .= '<%availability%><br />';
				$this->template_asbn .= '（価格・在庫状況は<%date%>現在）<br />';
				$this->template_asbn .= '</div>';
				$this->template_asbn .= '<div class="similars">';
				$this->template_asbn .= '<%similars%>';
				$this->template_asbn .= '</div>';
				break;
        }
    }
}
