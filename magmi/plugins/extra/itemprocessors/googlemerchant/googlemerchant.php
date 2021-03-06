<?php
/**
 * Class SampleItemProcessor
 * @author caiofior
 *
 * This class is a sample for item processing   
*/ 
class GoogleMerchant extends Magmi_ItemProcessor
{

    /**
     * Merchant handle
     * @var resource
     */
    private $googleMerchantHandle;
    /**
     *
     * @var type Gets config data
     */
    private $config;
    /**
     * Columns
     * @var array
     */
    private $columns = array(
        'id'=>null,
        'title'=>null,
        'description'=>null,
        'google product category'=>null,
        'product type'=>null,
        'link'=>null,
        'image link'=>null,
        'condition'=>null,
        'price'=>null,
        'sale_price'=>null,
        'availability'=>null,
        'brand'=>null,
        'mpn'=>null,
        //'gtin'=>null,
        'shipping'=>null

    );
    /**
     * Google merchants Categories
     * @var array
     */
    private $googleMerchantCategories =array();
    /**
     * Table rates data
     * @var array
     */
    private $tableRates = array();
    /**
     * Array of ean code of the products with custom price
     * @var array
     */
    private $customPrice = array();
    /**
     * Returns plugin informations
     * @return array
     */
    public function getPluginInfo()
    {
        return array(
            "name" => "Google merchant center CSV generator",
            "author" => "caiofior",
            "version" => "0.1"
        );
    }
    /**
     * Start up operations
     * @param array $params
     */
	public function initialize($params)
	{
            $this->config = array();
            foreach($this->selectAll(
                    'SELECT `path`,`value` FROM `core_config_data`
                     WHERE `scope` = "default" AND `path` = "web/unsecure/base_url"') as $value) {
                $this->config [$value['path']]=$value['value'];
            }
            foreach($this->selectAll(
                    'SELECT 
	(SELECT `value` 
	FROM `catalog_category_entity_varchar` 
	WHERE 
	`catalog_category_entity_varchar`.`attribute_id`= (SELECT `attribute_id` FROM `eav_attribute` WHERE
                            `attribute_code`="name" AND `entity_type_id`= 
                                (SELECT `entity_type_id` FROM  `eav_entity_type` WHERE
                                `entity_type_code`="catalog_category"
                                )
                            ) AND
	`catalog_category_entity_varchar`.`entity_id`=`catalog_category_entity`.`entity_id`
LIMIT 1
	) as "name",
(SELECT `value` 
	FROM `catalog_category_entity_text` 
	WHERE 
	`catalog_category_entity_text`.`attribute_id`= (SELECT `attribute_id` FROM `eav_attribute` WHERE
                            `attribute_code`="google_merchant_category" AND `entity_type_id`= 
                                (SELECT `entity_type_id` FROM  `eav_entity_type` WHERE
                                `entity_type_code`="catalog_category"
                                )
                            ) AND
	`catalog_category_entity_text`.`entity_id`=`catalog_category_entity`.`entity_id`
LIMIT 1
	) as "google_merchant"
FROM `catalog_category_entity`
WHERE 
(SELECT `value` 
	FROM `catalog_category_entity_text` 
	WHERE 
	`catalog_category_entity_text`.`attribute_id`= (SELECT `attribute_id` FROM `eav_attribute` WHERE
                            `attribute_code`="google_merchant_category" AND `entity_type_id`= 
                                (SELECT `entity_type_id` FROM  `eav_entity_type` WHERE
                                `entity_type_code`="catalog_category"
                                )
                            ) AND
	`catalog_category_entity_text`.`entity_id`=`catalog_category_entity`.`entity_id`
LIMIT 1
	) <> ""') as $value) {
                $this->googleMerchantCategories[$value['name']]=$value['google_merchant'];
            }
            foreach($this->selectAll(
                    'SELECT `condition_value` as "weight", `price`
                    FROM `shipping_tablerate` ORDER BY `condition_value` ASC') as $value) {
                
                
                $this->tableRates[$value['weight']]=$value['price'];
            }
            $file = __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.
                    '..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'googlemerchant.csv';
		if (is_file($file))
                    unlink($file);    
            $this->googleMerchantHandle = fopen($file, 'w');
            $columns = array_keys($this->columns);
            fwrite($this->googleMerchantHandle, "\xEF\xBB\xBF".implode("\t",$columns).PHP_EOL);
            
            $customPriceFile = __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'
                .DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'
                .DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'import'
                .DIRECTORY_SEPARATOR.'custom_prices.csv';
            if (is_file($customPriceFile)) {
                $customPriceHandler = fopen($customPriceFile,'r');
                $header = fgetcsv($customPriceHandler,0,',','"',"\\");
                $header = array_map(create_function('$val', 'return preg_replace("/[^[:alnum:]]/","",$val);'),$header);
                while($data = fgetcsv($customPriceHandler,0,',','"',"\\")) {              
                  $this->customPrice[] = $data[array_search('EAN13',$header)];
                }
                $this->customPrice = array_unique($this->customPrice);
            }
	}
	/**
         * Add item to csv
         * @param array $item
         * @param array $params
         * @return boolean
         */
	public function processItemAfterId(&$item,$params=null)
	{
            $googleMerchantData = $this->columns;
            if (
                    (
                        $item['store'] != 'admin' && !in_array($item['sku'],$this->customPrice)
                    )
                        ||
                    (
                        $item['store'] == 'admin' && in_array($item['sku'],$this->customPrice)
                    )
               )
               return true;
            if ($item['store'] == 'storebaby') {
                $this->log("Special price for SKU '".$item["sku"]."' =>".$pid,"info");
            }
            $googleMerchantData['id']=$item['sku'];
            if ($item['name'] == '')
                return true;
            if (!is_numeric($item['qty']) || $item['qty'] < 1)
                return true;
            $googleMerchantData['title']=str_replace("\t",' ',$item['name']);
            $googleMerchantData['description']=substr(str_replace("\t",' ',$item['description']),0,500);
            $categories = preg_split('/[,\/]/',$item['categories']);
            $categories[]='Default Category';
            $category = '';
            foreach($categories as $categoriesItem) {
                if (key_exists($categoriesItem, $this->googleMerchantCategories) && strlen($this->googleMerchantCategories[$categoriesItem]) > strlen($category))
                        $category= $this->googleMerchantCategories[$categoriesItem];
            }
            $googleMerchantData['google product category']=$category;
            $googleMerchantData['product type']=  str_replace('/', ' > ', $item['categories']);
            $url_path = '';
            foreach($this->selectAll(
                    'SELECT `request_path` FROM `core_url_rewrite`
                    WHERE `id_path` LIKE "product/%" AND `product_id` = "'.$params['product_id'].'" 
                    ORDER BY `category_id` ASC LIMIT 1') as $value) {
                
                
                $url_path =$value['request_path'];
            }
            if ($url_path == '' ) return true;
            $googleMerchantData['link']=$this->config['web/unsecure/base_url'].'index.php/'.$url_path;
            $googleMerchantData['image link']=$this->config['web/unsecure/base_url'].'media/catalog/product/'.preg_replace('/\+\//','',$item['image']);
            $googleMerchantData['condition']='new';
            if ($item['price'] == '')
                return true;
            $googleMerchantData['price']=$item['price'].' EUR';
            
            /*$salePrice = $this->selectone(
               'SELECT MAX(`rule_price`) as "discounted_price" FROM `catalogrule_product_price` 
                WHERE `product_id` = "'.$params['product_id'].'" AND `rule_date`=CURDATE()'
            ,null,'discounted_price');
            if ($salePrice != '')
               $salePrice .= ' EUR';
            $googleMerchantData['sale_price']=$salePrice;*/
            $googleMerchantData['availability']='in stock';
            $googleMerchantData['brand']= preg_replace('/::.*/','',$item['manufacturer']);
            $googleMerchantData['mpn']=
               substr(trim(substr($googleMerchantData['brand'],0,3)).substr($item['sku'],4,3),0,3).
               str_pad(substr($item['sku'],9,4),4,'0',STR_PAD_LEFT);
            //$googleMerchantData['gtin']=$item['sku'];
            
            $shipExpense = '9.9';
            foreach($this->tableRates as $tWeight => $tPrice)
                if ($item['weight'] > $tWeight) $shipExpense = $tPrice;

            $googleMerchantData['shipping']='IT:::'.str_replace(',','.',$shipExpense).' EUR';
            foreach($googleMerchantData as $key=>$value) {
                 $googleMerchantData[$key]=str_replace("\t",' ',$value);
            }
            fwrite($this->googleMerchantHandle, implode("\t",$googleMerchantData).PHP_EOL);
		return true;
	}
        /**
         * After import executed method
         */
        public function afterImport() {
            fclose($this->googleMerchantHandle);
        }
	
}