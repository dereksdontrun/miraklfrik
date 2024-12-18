<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');

//https://lafrikileria.com/tareas_programadas/mirakl/proceso/MiraklCatalogo.php
//https://lafrikileria.com/test/tareas_programadas/mirakl/proceso/MiraklCatalogo.php

//25/06/2024 Programar esta tarea cron cada hora en punto
//https://lafrikileria.com/modules/miraklfrik/classes/MiraklCatalogoConnect.php?cron=true

//ESTE PROCESO ES PARA LA PLATAFORMA MIRAKL CONNECT, no envía los catálogos a cada Marketplace,eso lo haría MiraklCatalogoMarketplaceAPI.php

//25/06/2024 Se envían todos los productos vendibles o no, cumpliendo con algunas restricciones, independientemente de si tienen categoría worten, etc, eso ya no vale. Así, tanto si un producto se queda sin stock como si antes no estaba en Mirakl y ahora si, se irá actualizando cada hora. En cualquier caso este catálogo es meramente informativo para saber qué productos se comparten con los diferentes marketplaces con los que aún no hemos conectado, ya que para trabajar con cada marketplace lo hacemos en su plataforma.
//22/04/2024 Proceso para subir, actualizar o eliminar ¿? los productos para la plataforma Mirakl
//la API solo permite enviar productos de 1000 en 1000 como máximo, de modo que hay que configurarlo para ello, ya que podemos enviar más de 15000 cada hora.

// ini_set('error_log', _PS_ROOT_DIR_.'/modules/miraklfrik/log/error/php_error.log');

// // Turn on error reporting
// ini_set('display_errors', 1);
// // error_reporting(E_ALL); cambiamos para que no saque E_NOTICE
// error_reporting(E_ERROR | E_WARNING | E_PARSE | E_DEPRECATED | E_STRICT);

if (isset($_GET['cron']) && $_GET['cron'] == 'true') {
    $a = new MiraklCatalogoConnect();
} else {
    exit;
}


class MiraklCatalogoConnect
{
    //variable donde guardaremos los productos a repasar
    public $productos = array();

    //guardamos los productos en formato listo para la API
    public $productos_raw_json;

    public $productos_vendibles = 0;

    //veces que hemos llamado a la API para sincronizar productos
    public $llamadas_api = 0;

    public $id_lang = 1;    

    public $mensajes = array();

    public $error = 0;

    //array que contiene los id_supplier de proveedores que entran en el proceso para productos sin stock
    //03/06/2024 los sacamos de lafrips_configuration
    // Cerdá - 65, Karactermanía - 53, 24 - Redstring, 8 - Erik, 121 - Distrineo, 111 - Noble 
    // public $proveedores_sin_stock = array(65, 53, 24, 8, 111, 121);    
    public $proveedores_sin_stock;

    //array de ids de categorías de producto a evitar en lista de productos 
    //121 -> prepedido
    public $categorias_evitar = array(121);    

    //array que contiene los id_manufacturer de fabricantes a evitar enviar a Mirakl     
    public $fabricantes_evitar = array();   

    //array que contiene los id_supplier de proveedores a evitar enviar a Mirakl. Solo tendrá en cuenta si lo tienen por defecto en lafrips_product     
    // 108-Migueleto, 51-Frikilería, 161-Disfrazzes, 11-MyAnimeToys, 38-Hitwing Jewelry, 42-Bahamut, 89-New Import, 172-Minalima, 12-You Q, 
    // 117-DCARZZ JEWELRY
    public $proveedores_evitar = array(0, 108, 161, 51, 11, 38, 42, 89, 172, 12, 117);  

    //03/10/2024 Añadimos productos a evitar, pueden ser productos concretos que sabemos que mirakl tiene mal o nos van a dar problemas
    public $productos_evitar = array(58323,59613,28480,15557);  

    public $log = true;    

    //variable para el archivo a generar en el servidor con las líneas log
    public $log_file;   

    //carpeta de archivos log    
    public $log_path = _PS_ROOT_DIR_.'/modules/miraklfrik/log/catalogo_connect/'; 

    //carpeta para guardar un csv por cada proceso, con lo que se ha enviado a Mirakl
    public $csv_backup_path = _PS_ROOT_DIR_.'/modules/miraklfrik/csv/catalogo_mirakl_connect/'; 

    //para almacenar las credenciales para la conexión para solictar el access_token
    public $credentials = array();

    //para almacenar el access_token al solicitarlo
    public $access_token;
          

    public function __construct() {    
        
        date_default_timezone_set("Europe/Madrid");

        //antes de procesar los productos sacamos los id_supplier de proveedores sin stock permitidos para marketplaces, almacenados en lafrips_configuration 
        $this->proveedores_sin_stock = explode(",", Configuration::get('PROVEEDORES_VENTA_SIN_STOCK'));

        //preparamos log        
        $this->setLog();                                

        //obtenemos los productos a revisar
        $this->getProductos();                
               
        // echo '<pre>';
        // print_r($this->productos);
        // echo '</pre>';

        $this->getAccessToken();

        $this->setProductos();

        // $this->upsertProducts();        

        $this->generaCsv();

        $this->mensajes[] = "Proceso de sincronización de productos terminado"; 
        $this->mensajes[] = "Llamadas a API realizadas para sincronizar todo el catálogo: ".$this->llamadas_api;   
            
        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Proceso de sincronización de productos terminado".PHP_EOL, FILE_APPEND);   
        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Llamadas a API realizadas para sincronizar todo el catálogo: ".$this->llamadas_api.PHP_EOL, FILE_APPEND);       

        if ($this->error) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - PROCESO FINALIZADO CON ERRORES".PHP_EOL, FILE_APPEND);   

            $this->enviaEmail();
        }
        
    }    

    //función que limpia el servidor de archivos antiguos de log y prepara el de esta sesión
    public function setLog() {
        //cuando la hora de ejecución del proceso sea 05 buscaremos archivos con más de 30 días de antiguedad y los eliminaremos, de modo que se haga una vez al día.         
        if (date("H") == '05') {  
            // para ello usamos una función de PHP, filemtime() que nos da la fecha de creación del archivo (en realidad se supone que es la última modificación pero a mi me coincide con creación). El resultado lo da en segundos con lo que comparamos con time() que da la fecha actual en segundos y si la diferencia es superior al equivalente en segundos de 10 días, lo eliminamos con unlink.
            // Un día son 86400 segundos, *10 = 864000,*15 = 1296000, *30 = 2592000
            //sacamos todos los archivos de la carpeta log del servidor con extensión txt             
            $files = glob($this->log_path.'*.txt');

            //por cada uno sacamos su fecha en segundos y comparamos con now, si la diferencia es más de 20 días lo eliminamos
            foreach($files as $file) {   
                //10 dias 864000 segundos
                //20 dias 1728000 segundos
                $diferencia = time() - filemtime($file);
                if ($diferencia > 2592000) {
                    //eliminamos archivo
                    unlink($file);       
                }
            }
        }

        //preparamos nuevo archivo para esta sesión
        $this->log_file = $this->log_path.'proceso_catalogo_mirakl_connect_'.date('Y-m-d H:i:s').'.txt';
                   
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso catálogo Mirakl Connect'.PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Proveedores seleccionados para procesar sin stock '.implode(',', $this->proveedores_sin_stock).PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Fabricantes seleccionados para no procesar '.implode(',', $this->fabricantes_evitar).PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Proveedores seleccionados para no procesar '.implode(',', $this->proveedores_evitar).PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Categorías seleccionadas para no procesar '.implode(',', $this->categorias_evitar).PHP_EOL, FILE_APPEND);

        return;        
    }
       
    //obtiene los productos para gestionar. Hay que sacar los que tienen la categoría worten, obteniendo referencia, ean, nombre, por ahora en español, pvp, por ahora no ponemos el pvp descuento, el stock, que si es producto sin stock pero con permitir pedido será 999, y la imagen principal
    //para el stock sacamos el stock online disponible, es decir , restamos el stock físico de tienda al quantity de stock_available
    //id,gtin,title,standard-price,discount-price,available-quantity,image-url-1
    public function getProductos() {        
        
        if (empty($this->fabricantes_evitar)) {
            $evitar_fabricantes = "";
        } else {
            $evitar_fabricantes = " AND pro.id_manufacturer NOT IN (".implode(',', $this->fabricantes_evitar).") ";
        }

        //solo evita proveedores si están asignados como por defecto, de momento
        if (empty($this->proveedores_evitar)) {
            $evitar_proveedores = "";
        } else {
            $evitar_proveedores = " AND pro.id_supplier NOT IN (".implode(',', $this->proveedores_evitar).") ";
        }

        //03/10/2024
        if (empty($this->productos_evitar)) {
            $evitar_productos = "";
        } else {
            $evitar_productos = " AND pro.id_product NOT IN (".implode(',', $this->productos_evitar).") ";
        }
        
        //rellenamos de ceros a la izquierda en el ean
        $sql_productos = "SELECT 
        IFNULL(pat.reference, pro.reference) AS id, LPAD(IFNULL(pat.ean13, pro.ean13), 13, 0) AS gtin, pla.name as title,
        ROUND(pro.price*((tax.rate/100)+1),2) AS 'standard-price',
        CASE 
            WHEN spp.reduction_type = 'percentage' THEN ROUND(((pro.price*((tax.rate/100)+1)) - (pro.price*((tax.rate/100)+1) * spp.reduction)),2)	
            WHEN spp.reduction_type = 'amount'  THEN ROUND(((pro.price*((tax.rate/100)+1)) - spp.reduction),2)
        ELSE ''
        END
        AS 'discount-price',        
        CASE
        WHEN (ava.quantity - IFNULL((SELECT SUM(physical_quantity) FROM lafrips_stock 
			WHERE id_product = ava.id_product AND id_product_attribute = ava.id_product_attribute AND id_warehouse = 4),0)) > 0 
            THEN (ava.quantity - IFNULL((SELECT SUM(physical_quantity) FROM lafrips_stock 
			WHERE id_product = ava.id_product AND id_product_attribute = ava.id_product_attribute AND id_warehouse = 4),0))           
        WHEN ((ava.quantity < 1) AND (ava.out_of_stock = 1) AND (pro.id_supplier IN (".implode(',', $this->proveedores_sin_stock)."))) THEN 999
        ELSE 0
        END AS 'available-quantity',
        CONCAT( 'http://lafrikileria.com/', ima.id_image, '-large_default/', pla.link_rewrite, '.jpg') AS 'image-url-1'        
        FROM lafrips_stock_available ava
        JOIN lafrips_product pro ON pro.id_product = ava.id_product  
        JOIN lafrips_product_lang pla ON pla.id_product = ava.id_product AND pla.id_lang = ".$this->id_lang."
        LEFT JOIN lafrips_product_attribute pat ON pat.id_product = ava.id_product AND pat.id_product_attribute = ava.id_product_attribute
        JOIN lafrips_tax_rule tar ON pro.id_tax_rules_group = tar.id_tax_rules_group AND tar.id_country = 6
        JOIN lafrips_tax tax ON tax.id_tax = tar.id_tax
        JOIN lafrips_image ima ON ima.id_product = ava.id_product AND ima.cover = 1
        LEFT JOIN lafrips_specific_price spp ON pro.id_product =  spp.id_product
            AND spp.from_quantity = 1    
            AND spp.id_specific_price_rule = 0
            AND spp.id_customer = 0
            AND spp.to = '0000-00-00 00:00:00' #evitamos descuentos pasados, no apareceran los temporales
        WHERE 1
        ".$evitar_fabricantes."
        ".$evitar_proveedores."
        ".$evitar_productos."
        AND IFNULL(pat.ean13, pro.ean13) != ''
        AND pro.is_virtual = 0
        AND pro.cache_is_pack = 0
        AND pro.active = 1
        AND pro.id_product NOT IN (SELECT id_product FROM lafrips_category_product WHERE id_category IN (".implode(',', $this->categorias_evitar)."))          
        AND ( 
            IF(EXISTS(SELECT id_product FROM lafrips_product_attribute WHERE id_product = ava.id_product) AND (ava.id_product_attribute = 0), 0, 1)
        )
        ORDER BY ava.id_product, ava.id_product_attribute ASC";      

        $this->productos = Db::getInstance()->ExecuteS($sql_productos);        

        if (!$this->productos || !is_array($this->productos) || count($this->productos) < 1) {
            $this->error = 1;
            $this->mensajes[] = "No se pudieron obtener los productos desde la Base de Datos";            

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - No se pudieron obtener los productos desde la Base de Datos'.PHP_EOL, FILE_APPEND);     
            
            return;
        } 
                      
        $this->mensajes[] = "Productos obtenidos para exportar/sincronizar con Mirakl = ".count($this->productos);  
            
        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Productos obtenidos para exportar/sincronizar con Mirakl = ".count($this->productos).PHP_EOL, FILE_APPEND); 
                
        return;
    }       

    //función que envía los productos vía API, usamos el mismo nombre que tiene Mirakl connect API:connect, upsertProducts
    public function upsertProducts() {

        $this->llamadas_api++;

        // echo '<pre>';
        // print_r(json_decode($this->productos_raw_json));
        // echo '</pre>';

        // $array_productos = array(
        //     "products" => array()
        // );

        // $product = array(
        //     "id" => "HAR15020816",
        //     "gtins" => array(
        //         array(
        //             "value" => "3760166568345"
        //         )
        //     ),
        //     "titles" => array(
        //         array(
        //             "value" => "Guantes Slytherin Harry Potter",
        //             "locale" => "es_ES"
        //         )
        //     ),
        //     "images" => array(
        //         array(
        //             "url" => "http://lafrikileria.com/3064-large_default/guantes-slytherin-harry-potter.jpg"
        //         )    
        //     ),
        //     "standard_prices" => array(
        //         array(
        //             "price" => array(
        //                 "amount" => 17.50,
        //                 "currency" => "EUR"
        //             )
        //         )                
        //     ),
        //     "quantities" => array(
        //         array(
        //             "available_quantity" => 1
        //         )                
        //     )
        // );

        // $array_productos['products'][] = $product;

        // $this->productos_raw_json =  json_encode($array_productos); 
        

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://miraklconnect.com/api/products',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $this->productos_raw_json,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$this->access_token
        ),
        ));

        try {
            //ejecutamos cURL
            $response = curl_exec($curl);
        
            //si ha ocurrido algún error, lo capturamos
            if(curl_errno($curl)){
                throw new Exception(curl_error($curl));
            }
        }
        catch (Exception $e) {    
            $exception = $e->getMessage();
            $file = $e->getFile();
            $line = $e->getLine(); 
            $code = $e->getCode();

            $error_message = 'Error API Mirakl para petición upsertProducts en llamada '.$this->llamadas_api.' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        if ($response) {            
            // $curl_info = curl_getinfo($curl);

            // $connect_time = $curl_info['connect_time'];
            // $total_time = $curl_info['total_time'];

            // echo '<pre>';
            // print_r($response);
            // echo '</pre>';
            
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response);             

            if (($http_code < 200) || ($http_code > 299)) {
                if ($response_decode->code) {
                    $mensaje_error = "raw response: ".$response;
                } else {
                    $mensaje_error = "Mensaje error no definido";
                }

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API a petición upsertProducts en llamada '.$this->llamadas_api.' no es correcta'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API a petición upsertProducts en llamada '.$this->llamadas_api.' no es correcta'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Message: '.$mensaje_error;                

                return false;
            }

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API a petición upsertProducts en llamada '.$this->llamadas_api.' correcta'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);

            return true;            

        } else {
            //la API parece que no devuelve nada cuando el proceso es correcto, de modo que metemos este pequeño mensaje
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API a petición upsertProducts en llamada '.$this->llamadas_api.' vacía pero ¿correcta?'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);

            return true;
        }

    }

    //función que prepara el json de productos a enviar a la api y la llama. La api solo permite 1000 productos por llamada. Vamos a hacer que el proceso lleve una cuenta, envíe los productos cuando llega a 900 y luego continue reseteando dicha cuenta, hasta que termine
    public function setProductos() {
        $contador = 0;

        $array_productos = array(
            "products" => array()
        );

        foreach ($this->productos AS $producto) {
            $product = array(
                "id" => $producto['id'],
                "gtins" => array(
                    array(
                        "value" => $producto['gtin']
                    )
                ),
                "titles" => array(
                    array(
                        "value" => $producto['title'],
                        "locale" => "es_ES"
                    )
                ),
                "images" => array(
                    array(
                        "url" => $producto['image-url-1']
                    )    
                ),
                "standard_prices" => array(
                    array(
                        "price" => array(
                            "amount" => $producto['standard-price'],
                            "currency" => "EUR"
                        )
                    )                
                ),
                "discount_prices" => array(
                    array(
                        "price" => array(
                            "amount" => "", #por ahora no ponemos descuento, sustituir por $producto['discount-price'] si lo hicieramos
                            "currency" => "EUR"
                        )
                    )                
                ),
                "quantities" => array(
                    array(
                        "available_quantity" => $producto['available-quantity']
                    )                
                )
            );
    
            $array_productos['products'][] = $product;

            if ($producto['available-quantity'] > 0) {
                $this->productos_vendibles++;
            }

            $contador++;

            if ($contador == 900) {
                //llamamos a API con los productos que llevamos
                $this->productos_raw_json =  json_encode($array_productos); 

                if ($this->upsertProducts()) {
                    $this->mensajes[] = "Bloque ".$this->llamadas_api." de productos enviados correctamente";   
                          
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Bloque '.$this->llamadas_api.' de productos enviados correctamente'.PHP_EOL, FILE_APPEND);
                } else {
                    $this->error = 1;

                    $this->mensajes[] = "Atención: Bloque ".$this->llamadas_api." de productos con incidencia en envío";   
                          
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención: Bloque '.$this->llamadas_api.' de productos con incidencia en envío'.PHP_EOL, FILE_APPEND);
                }

                //reseteamos $contador, $array_productos y $productos_raw_json
                $contador = 0;

                $array_productos = array(
                    "products" => array()
                );

                $this->productos_raw_json =  "";
            }
        }

        // $this->productos_raw_json =  json_encode($array_productos); 

        $this->mensajes[] = "Productos con stock ".$this->productos_vendibles;   
                          
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Productos con stock/permitir pedido '.$this->productos_vendibles.PHP_EOL, FILE_APPEND);

        return;

        // $this->productos_raw_json = `{
        //     "products": [
        //         {
        //             "id": "HAR15020816",
        //             "gtins": [
        //                 {
        //                     "value": "3760166568345"
        //                 }
        //             ],
        //             "titles": [
        //                 {
        //                     "value": "Guantes Slytherin Harry Potter",
        //                     "locale": "es_ES"
        //                 }
        //             ],
        //             "images": [
        //                 {
        //                     "url": "http://lafrikileria.com/3064-large_default/guantes-slytherin-harry-potter.jpg"
        //                 }
        //             ],
        //             "standard_prices": [
        //                 {
        //                     "price": {
        //                         "amount": 27.50,
        //                         "currency": "EUR"
        //                     }
        //                 }
        //             ],
        //             "quantities": [
        //                 {
        //                     "available_quantity": 11
        //                 }
        //             ]
        //         }
        //     ]
        // }`;


        
        // "discount_prices": [
        //     {
        //         "price": {
        //             "amount": 21.50,
        //             "currency": "EUR"
        //         }
        //     }
        // ],

    }

    //función para pedir un access_token a la API de Mirakl, se debe ejecutar cada vez ya que el token caduca en una hora
    public function getAccessToken() {
        //sacamos todas las credenciales del archivo mirakl_connect_credentials.json, que hemos metido en el directorio secrets
        $this->credentials = $this->getCredentials();

        $array = array(
            "grant_type" => "client_credentials",            
            "client_id" => $this->credentials['client_id'],
            "client_secret" => $this->credentials['client_secret'],
            "audience" => $this->credentials['audience']
        ); 

        //como la llamada a esta API requiere enviar los parámetros como parte del body en formato x-www-form-urlencoded, en lugar de meterloa a json_encode lo pasamos por http_build_query para ese formato url
        $post_fields = http_build_query($array);        

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://auth.mirakl.net/oauth/token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded'
        ),
        ));

        try {
            //ejecutamos cURL
            $response = curl_exec($curl);
        
            //si ha ocurrido algún error, lo capturamos
            if(curl_errno($curl)){
                throw new Exception(curl_error($curl));
            }
        }
        catch (Exception $e) {    
            $exception = $e->getMessage();
            $file = $e->getFile();
            $line = $e->getLine(); 
            $code = $e->getCode();

            $error_message = 'Error API Mirakl para petición de access token - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        if ($response) {            
            // $curl_info = curl_getinfo($curl);

            // $connect_time = $curl_info['connect_time'];
            // $total_time = $curl_info['total_time'];

            // echo '<pre>';
            // print_r($response);
            // echo '</pre>';
            
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response);             

            if ($http_code != 200) {
                if ($response_decode->error) {
                    $mensaje_error = "error: ".$response_decode->error." - error_description: ".$response_decode->error_description;
                } else {
                    $mensaje_error = "Mensaje error no definido";
                }

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API a petición de access_token no es correcta'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = ' - Atención, la respuesta de la API a petición de access_token no es correcta'; 
                $this->mensajes[] = ' - Http Response Code = '.$http_code;
                $this->mensajes[] = ' - API Message: '.$mensaje_error;                

                return false;
            }

            $this->access_token = $response_decode->access_token;

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Access Token obtenido correctamente'.PHP_EOL, FILE_APPEND);

            return true;            
        }

    }

    public function getCredentials() {
        //Obtenemos la key leyendo el archivo mirakl_connect_credentials.json donde hemos almacenado la contraseña para la API
        $secrets_json = file_get_contents(dirname(__FILE__).'/../secrets/mirakl_connect_credentials.json');
        
        return json_decode($secrets_json, true);        
    }

    //función que genera el archivo csv para los productos a exportar. Deberá generar una copia en /csv_mirakl_backup que guardaremos 30 días
    //solo para log, de momento
    //Cabecera (por ahora no pondremos el discount-price) o si
    //id;gtin;title;standard-price;discount-price;available-quantity;image-url-1
    public function generaCsv() {
        //a las 5 de la mañana eliminamos los csv backup de más de 15 dias
        //29/11/2024 como se ejecuta dos veces al dia le ponemos que limpie en cada ejecución
        // if (date("H") == '05') {  
            // para ello usamos una función de PHP, filemtime() que nos da la fecha de creación del archivo (en realidad se supone que es la última modificación pero a mi me coincide con creación). El resultado lo da en segundos con lo que comparamos con time() que da la fecha actual en segundos y si la diferencia es superior al equivalente en segundos de 10 días, lo eliminamos con unlink.
            // Un día son 86400 segundos, *10 = 864000,*15 = 1296000, *30 = 2592000
            //sacamos todos los archivos de la carpeta log del servidor con extensión txt             
            $files = glob($this->csv_backup_path.'*.csv');

            //por cada uno sacamos su fecha en segundos y comparamos con now, si la diferencia es más de 20 días lo eliminamos
            foreach($files as $file) {   
                //10 dias 864000 segundos
                //20 dias 1728000 segundos
                $diferencia = time() - filemtime($file);
                if ($diferencia > 1296000) {
                    //eliminamos archivo
                    unlink($file);       
                }
            }
        // }        

        //preparamos el archivo para subir al FTP
        $delimiter = ";";     
        $filename = "catalogo_mirakl_connect_".date('Y-m-d_H:i:s').".csv";        
            
        //creamos el puntero del csv, para escritura        
        $file = fopen($this->csv_backup_path.$filename,'w');

        if ($file === false) {
            $this->error = 1;
            $this->mensajes[] = "Error generando el archivo csv backup de catálogo";   
                          
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error generando el archivo csv backup de catálogo'.PHP_EOL, FILE_APPEND);

            return;
        }

        //metemos la primera línea con las cabeceras
        //id;gtin;title;standard-price;discount-price;available-quantity;image-url-1
        $linea_csv = array("id","gtin","title","standard-price","discount-price","available-quantity","image-url-1");
        fputcsv($file, $linea_csv, $delimiter);        

        foreach($this->productos AS $producto) {            

            //por ahora enviamos el descuento vacío, pero en el csv lo ponemos
            $linea_csv = array($producto['id'],$producto['gtin'],$producto['title'],$producto['standard-price'],$producto['discount-price'],$producto['available-quantity'],$producto['image-url-1']);

            fputcsv($file, $linea_csv, $delimiter);   
            
        }

        //cerramos el puntero / archivo csv        
        if (fclose($file) === false) {
            $this->error = 1;
            $this->mensajes[] = "Error cerrando el archivo csv backup de catálogo";   
                          
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error cerrando el archivo csv backup de catálogo'.PHP_EOL, FILE_APPEND);

            return;
        }        
       
        return;
    }       


    //Envía un email con el contenido de los mensajes de error
    public function enviaEmail() {
        if (empty($this->mensajes)) {
            $this->mensajes = "todo OK";
        }
        // echo '<br>En enviaEmail()';
        // echo '<pre>';
        // print_r($this->mensajes);
        // echo '</pre>';

        if ($this->log) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Fin del proceso, dentro de enviaEmail '.PHP_EOL, FILE_APPEND);  
        }            

        $cuentas = 'sergio@lafrikileria.com';

        $asunto = 'ERROR actualizando catálogo para Mirakl Connect '.date("Y-m-d H:i:s");
        $info = [];                
        $info['{employee_name}'] = 'Usuario';
        $info['{order_date}'] = date("Y-m-d H:i:s");
        $info['{seller}'] = "";
        $info['{order_data}'] = "";
        $info['{messages}'] = '<pre>'.print_r($this->mensajes, true).'</pre>';
        
        @Mail::Send(
            1,
            'aviso_pedido_webservice', //plantilla
            Mail::l($asunto, 1),
            $info,
            $cuentas,
            'Usuario',
            null,
            null,
            null,
            null,
            _PS_MAIL_DIR_,
            true,
            1
        );

        exit;
    }

}

unset($a);


