<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');

//https://lafrikileria.com/tareas_programadas/mirakl/proceso/MiraklMarketplaceAPI.php
//https://lafrikileria.com/test/tareas_programadas/mirakl/proceso/MiraklMarketplaceAPI.php

//25/06/2024 Programar esta tarea cron cada hora más 5 minutos
//https://lafrikileria.com/modules/miraklfrik/classes/MiraklCatalogoMarketplaceAPI.php?cron=true

//25/06/2024 Metemos la clase en módulo miraklfrik

//30/04/2024 Proceso para enviar catálogo vía API. En principio es para Worten, vamos a ver si puedo hacerlo compatible para todos los marketplaces de Mirakl añadiendo cada Endpoint y API Key
//31/05/2024 Este proceso lo utilizamos para todos los marketplaces para actualizar el stock cada hora, y tenemos que adaptarlo para enviar el pvp para cada canal. Para ello, por ejemplo con worten tenemos que enviar dos columnas más, price[channel=WRT_ES_ONLINE] y price[channel=WRT_PT_ONLINE] y haremos la prueba de enviar las mismas columnas para cada canal de cada uno de los otros marketplaces

//09/07/2024 Hay que integrar diferentes cambios de moneda como en frik_amazon_reglas, ya que hemos introducido el Marketplace Empik de Polonia y los pvp han de ir en su moneda.

// ini_set('error_log', _PS_ROOT_DIR_.'/modules/miraklfrik/log/error/php_error.log');

// // Turn on error reporting
// ini_set('display_errors', 1);
// // error_reporting(E_ALL); cambiamos para que no saque E_NOTICE
// error_reporting(E_ERROR | E_WARNING | E_PARSE | E_DEPRECATED | E_STRICT);

if (isset($_GET['cron']) && $_GET['cron'] == 'true') {
    $a = new MiraklCatalogoMarketplaceAPI();
} else {
    exit;
}

class MiraklCatalogoMarketplaceAPI
{
    //variable donde guardaremos los productos a repasar
    public $productos = array();    

    public $productos_vendibles = 0;

    //veces que hemos llamado a la API para sincronizar productos
    public $llamadas_api = 0;

    public $id_lang = 1;    

    public $mensajes = array();

    public $error = 0;

    //array que contiene los id_supplier de proveedores que entran en el proceso para productos sin stock
    //03/06/2024 los sacmos de lafrips_configuration
    // Cerdá - 65, Karactermanía - 53, 24 - Redstring, 8 - Erik, 121 - Distrineo, 111 - Noble 
    // public $proveedores_sin_stock = array(65, 53, 24, 8, 111, 121);    
    public $proveedores_sin_stock;

    //array de ids de categorías de producto a evitar en lista de productos 
    //121 -> prepedido
    public $categorias_evitar = array(121);

    //por ahora usamos la categoría Worten para seleccionar los productos, pero esto se cambiará TODO, o se sacarán todos
    public $categoria_origen = 2545;

    //array que contiene los id_manufacturer de fabricantes a evitar enviar a Mirakl     
    public $fabricantes_evitar = array();   

    //array que contiene los id_supplier de proveedores a evitar enviar a Mirakl. Solo tendrá en cuenta si lo tienen por defecto en lafrips_product     
    // 108-Migueleto, 51-Frikilería, 161-Disfrazzes, 11-MyAnimeToys, 38-Hitwing Jewelry, 42-Bahamut, 89-New Import, 172-Minalima, 12-You Q, 
    // 117-DCARZZ JEWELRY
    public $proveedores_evitar = array(0, 108, 161, 51, 11, 38, 42, 89, 172, 12, 117);   

    public $log = true;    

    //variable para el archivo a generar en el servidor con las líneas log
    public $log_file;   

    //carpeta de archivos log    
    public $log_path = _PS_ROOT_DIR_.'/modules/miraklfrik/log/catalogos_marketplaces/'; 

    //carpeta para guardar un csv por cada proceso, con lo que se ha enviado a Mirakl
    public $csv_backup_path = _PS_ROOT_DIR_.'/modules/miraklfrik/csv/catalogos_marketplaces/'; 

    //localización del csv en proceso para pasar a la API, dirección y nombre de archivo en servidor
    public $csv_path;

    //para almacenar las credenciales para la conexión a la API según el Marketplace. Tendrá formato array($end_point, $api_key)
    //por ahora también meteré algunas variables por marketplace, por ejemplo, si exportar productos con venta sin stock o no, etc
    public $marketplaces_credentials = array();    

    //variables donde se almacena el marketplace que estamos procesando, su out_of_stock, su modificacion_pvp, el id en tabla lafrips_mirakl_marketplaces
    public $id_mirakl_marketplace;
    public $marketplace;
    public $out_of_stock;
    public $modificacion_pvp;
    public $campos_especificos = array();
    public $additional_fields = array();
    public $end_point;
    public $shop_key;
    public $import_id;

    //31/05/2024 variable para guardar la info de marketplaces que sacaremos de lafrips_mirakl_marketplaces en lugar de utilizar el array $marketplace_configuration
    public $marketplaces;
    public $marketplace_channels;
             

    public function __construct() {    

        date_default_timezone_set("Europe/Madrid");

        //antes de procesar los productos sacamos los id_supplier de proveedores sin stock permitidos para marketplaces, almacenados en lafrips_configuration 
        $this->proveedores_sin_stock = explode(",", Configuration::get('PROVEEDORES_VENTA_SIN_STOCK'));

        //preparamos log        
        $this->setLog();   

        if (!$this->getCredentials()) {
            $this->enviaEmail();

            exit;
        }

        //obtenemos los productos a revisar
        if (!$this->getProductos()) {
            $this->enviaEmail();
            
            exit;
        }        

        //llamamos a función que procesará los productos para cada marketplace
        if (!$this->procesaMarketplaces()) {
            $this->enviaEmail();
        }   

        $this->mensajes[] = "Proceso de sincronización de productos con marketplaces terminado"; 
        #$this->mensajes[] = "Llamadas a API realizadas para sincronizar todo el catálogo: ".$this->llamadas_api;   
            
        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Proceso de sincronización de productos con marketplaces terminado".PHP_EOL, FILE_APPEND);   
        #file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Llamadas a API realizadas para sincronizar todo el catálogo: ".$this->llamadas_api.PHP_EOL, FILE_APPEND);       

        if ($this->error) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - PROCESO FINALIZADO CON ERRORES".PHP_EOL, FILE_APPEND);   

            $this->enviaEmail();
        }
        
    }    

    //función que hace un bucle recorriendo los marketplaces a procesar y los productos, generando el csv para cada marketplace y llamando a la API para subirlo
    //31/05/2024 En lugar de utilizar el array marketplace_configuration vamos a empezar a tirar de las tablas mirakl_marketplaces y mirakl_channels que he creado, para poder usar el mismo código en diferentes procesos. En lugar del foreach ($this->marketplace_configuration AS $key => $value) vamos a sacar lso marketplaces activos de mirakl_marketplaces dado que para formar el csv de stock y precios solo enviamos uno por marketplace, y luego para añadir el precio por canal tiraremos de mirakl_channels
    public function procesaMarketplaces() {

        $sql_marketplaces = "SELECT * FROM lafrips_mirakl_marketplaces WHERE active = 1";

        $this->marketplaces = Db::getInstance()->ExecuteS($sql_marketplaces);      

        if (!$this->marketplaces || !is_array($this->marketplaces) || count($this->marketplaces) < 1) {
            $this->error = 1;
            $this->mensajes[] = "No se pudo obtener la información de los marketplaces desde la Base de Datos";            

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - No se pudo obtener la información de los marketplaces desde la Base de Datos'.PHP_EOL, FILE_APPEND);     
            
            return false;
        } 

        foreach ($this->marketplaces AS $marketplace) {               
            //preparamos las variables del marketplace
            $this->id_mirakl_marketplace = $marketplace['id_mirakl_marketplaces'];

            $this->marketplace = $marketplace['marketplace'];

            $this->out_of_stock = $marketplace['out_of_stock'];

            $this->modificacion_pvp = $marketplace['modificacion_pvp'];

            //decodificamos el json almacenado en tabla a array PHP
            $this->campos_especificos = json_decode($marketplace['campos_especificos'], true);

            //decodificamos el json almacenado en tabla a array PHP
            $this->additional_fields = json_decode($marketplace['additional_fields'], true);

            //31/05/2024 Ahora queremos añadir otra columna de price por cada canal del marketplace de la forma price[channel=WRT_PT_ONLINE]. Sacamos los canales activos del marketplace en proceso desde la tabla lafrips_mirakl_channels
            $sql_channel_codes = "SELECT channel_code, principal FROM lafrips_mirakl_channels WHERE active = 1 AND marketplace_id = ".$this->id_mirakl_marketplace;

            $this->marketplace_channels = Db::getInstance()->ExecuteS($sql_channel_codes);   

            if (!$this->marketplace_channels || !is_array($this->marketplace_channels) || count($this->marketplace_channels) < 1) {
                $this->error = 1;
                $this->mensajes[] = "No se pudo obtener la información de los canales de marketplaces desde la Base de Datos o no hay ninguno activo";            
    
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - No se pudo obtener la información de los canales de marketplaces desde la Base de Datos o no hay ninguno activo'.PHP_EOL, FILE_APPEND);     
                
                return false;
            } 

            //url endponit y shop_key sacamos de credentials            
            $this->end_point = $this->marketplaces_credentials[$this->marketplace]['url'];

            $this->shop_key = $this->marketplaces_credentials[$this->marketplace]['shop_key'];

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Marketplace '.ucfirst($this->marketplace).':'.PHP_EOL, FILE_APPEND);  
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Productos sin stock con permitir pedido: '.($this->out_of_stock == 1 ? 'SI' : 'NO').PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Modificación PVP: '.($this->modificacion_pvp == 0 ? 'NO' : $this->modificacion_pvp.'%').PHP_EOL, FILE_APPEND);
            $canales = array();  
            foreach ($this->marketplace_channels AS $canal) {
                $canales[] = $canal['channel_code'];
            }
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Canales activos: '.implode(",", $canales).PHP_EOL, FILE_APPEND); 

            //preparamos el csv            
            if (!$this->generaCsv()) {
                //error generando csv, pasamos a siguiente marketplace                
                continue;
            } 
            // exit;

            // ya tendriamos el csv del marketplace en $this->csv_path y todo lo necesario para llamar a la API OF01
            if (!$this->apiOF01ImportOffersFile()) {
                //error llamando a API, pasamos a siguiente marketplace                
                continue;
            } 
            
            //enviado el csv, llamamos a API para comprobar si hay errores con el id import recibido



        }

        return true;
    }

    //función que importa al marketplace correspondiente el csv de ofertas generado
    //tenemos el csv en $this->csv_path, el endpoint y shop_key del marketplace en $this->end_point y $this->shop_key
    //utilizamos la API de Mirakl Seller API: OF01 mmp /api/offers/imports que permite añadir un archivo a la llamada
    public function apiOF01ImportOffersFile() {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/offers/imports',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array(
                'file'=> new CURLFILE($this->csv_path),
                'import_mode' => 'NORMAL'
            ),
            CURLOPT_HTTPHEADER => array(
                'Authorization: '.$this->shop_key,
                'Accept: application/json',
                'Content-Type: multipart/form-data'
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

            $error_message = 'Error API Mirakl para petición api/offers/imports para marketplace '.ucfirst($this->marketplace).' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

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
                if ($response_decode->message) {
                    $mensaje_error = $response_decode->message;
                } else {
                    $mensaje_error = "Mensaje error no definido";
                }

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API a petición api/offers/imports para marketplace '.ucfirst($this->marketplace).' no es correcta'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API a petición api/offers/imports para marketplace '.ucfirst($this->marketplace).' no es correcta'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Message: '.$mensaje_error;                

                return false;
            }

            //si la llamada es correcta devuelve un código de importación
            $this->import_id = $response_decode->import_id;

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API a petición api/offers/imports para marketplace '.ucfirst($this->marketplace).' correcta'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Código de importación recibido - import_id = '.$this->import_id.PHP_EOL, FILE_APPEND);

            return true;            

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API a petición api/offers/imports para marketplace '.ucfirst($this->marketplace); 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }
    }

    //función que genera el archivo csv para los productos a exportar, en función de las características del marketplace, es decir, si se permite venta sin stock, si hay que modificar el pvp, si tiene campos específicos a ese marketplace, etc. Se genera uno por marketplace que se almacenará 15 días en el servidor /csv_marketplaces
    //todos los csv tendrán unos campos comunes a todos los marketplaces. A 08/05/2024:
    //sku;product-id;product-id-type;price;quantity;state;available-start-date;available-end-date;discount-price;discount-start-date;discount-end-date;update-delete;
    //Los de desceunto de momento no usamos pero los dejamos preparados. campos_especificos o additional_fields son:
    //leadtime-to-ship;strike-price-type;canon;tipo-iva;
    //15/07/2024 cambiado campos espc´diifcos, repartimos con additional fields para diferenciar los que solo existen en un marketplaces de los que son comunes aunque no se usen
    //31/05/2024 tenemos que adaptarlo para enviar el pvp para cada canal. Para ello, por ejemplo con worten tenemos que enviar dos columnas más, price[channel=WRT_ES_ONLINE] y price[channel=WRT_PT_ONLINE] y haremos la prueba de enviar las mismas columnas para cada canal de cada uno de los otros marketplaces. En lugar de tratarlo como los campos específicos, lo construiremos sacando el channel code de cada canal y creando así el csv
    public function generaCsv() {
        //a las 5 de la mañana eliminamos los csv backup de más de x dias. Como ocupan mucho los voy a dejar 8 días contando qcon que hay copia en cada marketplace en la sección de importación
        if (date("H") == '05') {  
            // para ello usamos una función de PHP, filemtime() que nos da la fecha de creación del archivo (en realidad se supone que es la última modificación pero a mi me coincide con creación). El resultado lo da en segundos con lo que comparamos con time() que da la fecha actual en segundos y si la diferencia es superior al equivalente en segundos de 10 días, lo eliminamos con unlink.
            // Un día son 86400 segundos, *5= 432000, *8 = 691200,*10 = 864000,*15 = 1296000, *30 = 2592000
            //sacamos todos los archivos de la carpeta log del servidor con extensión txt             
            $files = glob($this->csv_backup_path.'*.csv');

            //por cada uno sacamos su fecha en segundos y comparamos con now, si la diferencia es más de 8 días lo eliminamos
            foreach($files as $file) {   
                //10 dias 864000 segundos
                //20 dias 1728000 segundos
                $diferencia = time() - filemtime($file);
                if ($diferencia > 691200) {
                    //eliminamos archivo
                    unlink($file);       
                }
            }
        }        

        //preparamos el archivo para subir al FTP
        $delimiter = ";";     
        $filename = $this->marketplace."_catalogo_mirakl_".date('Y-m-d_H:i:s').".csv";        

        $this->csv_path = $this->csv_backup_path.$filename;
            
        //creamos el puntero del csv, para escritura        
        $file = fopen($this->csv_path,'w');

        if ($file === false) {
            $this->error = 1;
            $this->mensajes[] = "Error generando el archivo csv de catálogo para ".ucfirst($this->marketplace);   
                          
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Error generando el archivo csv de catálogo para ".ucfirst($this->marketplace).PHP_EOL, FILE_APPEND);

            return false;
        }

        //tenemos un array con la primera línea de cabecera con los campos comunes a todos los marketplaces. Le metemos los específicos de cada marketplace
        //06/06/2024 Vamos a probar a poner el "price" o precio predeterminado igual qwue el pvp que pongamos al canal principal del marketplace, por ejemplo PT en worten. Para hacer esto, meteremos el campo price con los de cada canal en lugar de aquí.
        //sku;product-id;product-id-type;quantity;state;available-start-date;available-end-date;update-delete;
        $linea_csv = array("sku","product-id","product-id-type","quantity","state","available-start-date","available-end-date","update-delete");

        if (!empty($this->campos_especificos)) {
            foreach ($this->campos_especificos AS $key => $value) {
                $linea_csv[] = $key;
            }
        }         
        
        //15/07/2024 Añadimos additional_fields, los campos específicos propios de cada marketplace, es decir, no existen en principio en otros marketplaces
        if (!empty($this->additional_fields)) {
            foreach ($this->additional_fields AS $key => $value) {
                $linea_csv[] = $key;
            }
        } 
        
        //31/05/2024 Ahora tenemos que añadir otra columna de price por cada canal del marketplace de la forma price[channel=WRT_PT_ONLINE]. Sacamos los canales activos del marketplace en proceso desde la tabla lafrips_mirakl_channels        
        foreach ($this->marketplace_channels AS $marketplace_channel) {
            $linea_csv[] = 'price[channel='.$marketplace_channel['channel_code'].']';
        }  
        //06/06/2024 Metemos el campo price aquí, después de los de canal, para luego cuando obtengamos el pvp de cada canal poder poner el mismo que para el canal principal
        $linea_csv[] = 'price';
        
        fputcsv($file, $linea_csv, $delimiter);    
        
        //state es NEW y su id es 11.
        $product_state = 11;

        //preparamos available-start-date y available-end-date. Parece ser la fecha de ejecución en formato YYYY-MM-DD y esa fecha más 30 días. Vamos a poner 10 días si traga de modo que si perdieramos el control de un producto, se desactivaría a los 10 días. Vamos a poner como fecha inicio hoy menos uno por un posible error de fechas en el cambio de día
        // $hoy = date("Y-m-d"); 
        $hoy_menos_1 = date("Y-m-d", strtotime("-1 days", strtotime(date("Y-m-d"))));
        $hoy_mas_10 = date("Y-m-d", strtotime("+10 days", strtotime(date("Y-m-d"))));

        foreach($this->productos AS $producto) {  
            //20/06/2024 quizás esto no lo activemos, pero he modificado MiraklOfertasPVP.php para que en el mismo proceso en el que descargamos las ofertas de los productos con otros vendedores y calculamos el pvp_exportado para competir por buybox, se exporte la info de la oferta de vuelta al marketplace y canal de Mirakl que estemos trabajando, de modo que hay que modificar este proceso para no volver a exportar esa información en el csv sino dejar el csv solo para los que o bien no están en los marketplaces y vamos probando o bien están pero no estaban activos por ejemplo por falta de stock y hay que seguir intentando subirlos. Para esto tenemos aquí un campo activo_mirakl que indica que id_product con id_product_attribute se encuentran en lafrips_mirakl_ofertas, pero no sabemos para qué marketplace o canal, o si está activo. Este campo lo he puesto para evitar perder tiempo con productos que ya tienen el pvp calculado en la tabla, pero ahora lo voy a usar para, o bien, si los ids no están en la tabla, saber que hay que enviarlo en el csv, o bien están y hay que comprobar si lo están para el canal en proceso, y activos, es decir, ya se han subido en el json via api y no hay que meterlos en el csv. Por eso, cada producto con activo_mirakl = 1 debemos asegurarnos de su situación. Se buscará si lo está y activo para el marketplace en proceso. Si no está, lo continuamos y se meterá en el csv, si está se considera que ya se actualizó en el otro proceso y pasamos al sigueinte producto, limpiando $linea_csv antes. Solo necesitamos mirar si está activo para el marketplace, porque si un producto está activo en un canal de un marketplace, lo está en todos (o eso creemos), de modo que en este punto vamos a la tabla y buscamos el producto activo para el marketplace, si no devuelve nada es que o bien no está para ese marketplace y lo queremos añadir al csv, o la última vez que bajamos los activos no lo estaba e igualmente volvemos a enviarlo en csv por si ha cambiado su estado en Prestashop. Si lo encontramos activo para el marketplace pasamos al siguiente.
            if ($producto['activo_mirakl']) {
                //id_product e id_product_attribute están en tabla ofertas, comprobamos si lo están activos y para el marketplace en curso
                if ($this->checkOfertaActivaMArketplace($producto['id_product'], $producto['id_product_attribute'])) {
                    continue;
                }
            }

            //tenemos que asignar el stock si el producto no tuviera y es de permitir pedido según el marketplace. Primero, si es negativo (hemos sacado el disponible online) lo ponemos a 0
            if ($producto['quantity'] < 0) {
                $producto['quantity'] = 0;
            }

            //si el marketplace permite venta sin stock y el proveedor está configurado como admitido y el producto tiene permitir pedido, ponemos stock 999
            if ($this->out_of_stock && !$producto['quantity'] && $producto['permite_pedido_sin_stock'] == 1 && in_array($producto['id_supplier'], $this->proveedores_sin_stock)) {
                //se permite venta sin stock, envaimos 999
                $producto['quantity'] = 999;
            }    

            //si para el marketplace modificamos pvp lo procesamos, redondeando el resultado
            //06/06/2024 por ahora no utilizamos y he cambiado el orden de poner el price, lo dejo comentado
            // if ($this->modificacion_pvp !== 0) {
            //     $producto['price'] = $this->modificaPVP($producto['price'], $this->modificacion_pvp);
            // }

            //05/07/2024 El marketplace Leclerc no admite EAN en el catálogo escrito en mayúsculas, y los otros hasta ahora no lo admiten en minúscuals, de modo que meto aquí un condicional cutre para ponerlo en minúsculas para los señoritos
            if ($this->marketplace == 'leclerc') {
                $ean = 'ean';
            } else {
                $ean = 'EAN';
            }
            
            $linea_csv = array($producto['sku'], $producto['product-id'], $ean, $producto['quantity'], $product_state, $hoy_menos_1, $hoy_mas_10, "UPDATE");

            //ahora, si hay campos específicos añadimos los values al array $linea_csv
            //15/07/2024 Marketplace ePrice tiene un campo fulfillment-latency, por ahora le ponemos el mismo valor que leadtime_to_ship con el mismo cálculo de si tiene stock
            if (!empty($this->campos_especificos)) {
                foreach ($this->campos_especificos AS $key => $value) {
                    //si es tipo-iva lo hemos sacado en la consulta
                    if ($key == 'leadtime-to-ship') {
                        //el leadtime-to-ship lo hemos sacado para el proveedor por defecto del producto, y se pone ese si es venta sin stock, o 1 por defecto si hay stock físico. Si se vende sin stock 'quantity' será 999 en este punto
                        if ($producto['quantity'] == 999) {
                            $linea_csv[] = $producto['supplier_leadtime_to_ship'];
                        } else {
                            $linea_csv[] = 1;
                        }                    
                    } else {
                        //valores fijos
                        $linea_csv[] = $value;
                    }                
                }
            }             

            //15/07/2024 Añadimos additional_fields, los campos específicos propios de cada marketplace, es decir, no existen en principio en otros marketplaces        
            if (!empty($this->additional_fields)) {
                foreach ($this->additional_fields AS $key => $value) {
                    //si es tipo-iva lo hemos sacado en la consulta
                    if ($key == 'tipo-iva') {
                        $linea_csv[] = $producto['tipo-iva'];
                    } elseif ($key == 'fulfillment-latency') {
                        //al fulfillment-latency de momento le ponemos el valor de leadtime-to-ship 
                        if ($producto['quantity'] == 999) {
                            $linea_csv[] = $producto['supplier_leadtime_to_ship'];
                        } else {
                            $linea_csv[] = 1;
                        }                    
                    } else {
                        //valores fijos como canon = 0
                        $linea_csv[] = $value;
                    }                
                }
            }

            //31/05/2024 Ahora tenemos que añadir otra columna de price por cada canal del marketplace de la forma price[channel=WRT_PT_ONLINE]. 
            //05/06/2024 Tenemos que obtener el pvp para cada canal de la tabla lafrips_mirakl_ofertas, para ello buscaremos si el producto existe en dicha tabla para el canal que estamos procesando, y sacaremos pvp_exportado. Hemos sacado un campo "activo_mirakl" que indica si el producto está en la tabla, independientemente de si está activo o para qué marketplace, para ahorrar tiempo. Si no está de momento meteremos el pvp de prestashop, pero habría que calcular el "pvp_publicacion"
            //06/06/2024 Tenemos en lafrips_marketplace_channels un campo llamado principal que indica cual es el canal predeterminado o principal del Marketplace. No estoy seguro todavía de este funcionamiento, pero parece que hay que poner el mismo pvp al predeterminado (price) que al principal (por ejemplo PT en worten o ES en pccomponentes) para que se cambien los pvps al asignar diferentes a cada canal. Así, tenemos que asegurarnos de que en el campo price del csv vaya el mismo pvp que en el del canal principal. Para hacer esto necesito primero saber si el producto está en la tabla de mirakl_ofertas donde tendremos calculado un pvp a exportar por canal, teniendo que poner al price principal el mismo que el del canal "principal" pero además necesitamos tener el pvp_publicacion calculado para los productos que noe stán en la tabla. La razón de no estar en la tabla suele ser que no existen en el marketplace o ya no están activos, pero también puede ser porque el producto es nuevo, así que la exportación hay que hacerla de primeras ya con el pvp de publicación, que calcularemos aquí al vuelo.            
            foreach ($this->marketplace_channels AS $marketplace_channel) {               
                if ($producto['activo_mirakl']) {
                    if (!$pvps = $this->getPvps($producto['id_product'], $producto['id_product_attribute'], $marketplace_channel['channel_code'])) {
                        //podría ser un error en la tabla o probablemente que esté en la tabla pero para otro marketplace, lo cual no sabemos en la consulta getProductos. Si no se obtienen pvps para el producto, sacamos el de publicación desde aquí para ponerlo por defecto
                        $pvps['exportar'] = $this->getPvpPublicacion($producto['id_product'], $marketplace_channel['channel_code']);
                        $pvps['publicacion'] = $pvps['exportar'];
                    }

                    if (!$pvps['exportar'] || $pvps['exportar'] < 3) {
                        $pvp_exportar = $pvps['publicacion'];
                    } else {
                        $pvp_exportar = $pvps['exportar'];
                    }

                    //si es el canal principal, guardamos pvp_exportado para el price predeterminado
                    if ($marketplace_channel['principal']) {
                        $pvp_predeterminado = $pvps['exportar'];
                    }
                } else {
                    //aquí quizás deberíamos calcular el pvp + gastos envío + comisión  
                    $pvp_exportar = $this->getPvpPublicacion($producto['id_product'], $marketplace_channel['channel_code']);

                    //si es el canal principal, guardamos pvp_publicacion para el price predeterminado
                    if ($marketplace_channel['principal']) {
                        $pvp_predeterminado = $pvp_exportar;
                    }
                }                

                //si por una serie de errores $pvp_exportar ha quedado vacío o menor de 3€, ponemos pvp de prestashop, con tal de no dejar pvp vacío
                if (!$pvp_exportar || $pvp_exportar < 3) {
                    $this->error = 1;
                    $this->mensajes[] = "Error con pvp_exportar, ha quedado vacío o menor de 3€, asignamos pvp Prestashop, para producto ".$producto['id_product']."_".$producto['id_product_attribute']." y canal ".$marketplace_channel['channel_code']." para ".ucfirst($this->marketplace);   
                                
                    file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Error con pvp_exportar, ha quedado vacío o menor de 3€, asignamos pvp Prestashop, para producto ".$producto['id_product']."_".$producto['id_product_attribute']." y canal ".$marketplace_channel['channel_code']." para ".ucfirst($this->marketplace).PHP_EOL, FILE_APPEND);

                    $pvp_exportar = $producto['price'];
                }

                $linea_csv[] = $pvp_exportar;
                
            } 

            //finalmente metemos le campo price predeterminado, ahora que ya sabemos el pvp de publicacion del canal predeterminado, o en su defecto, si no está en lafrips_mirakl_ofertas, lo calculamos
            $linea_csv[] = $pvp_predeterminado;

            fputcsv($file, $linea_csv, $delimiter);   
            
        }

        //cerramos el puntero / archivo csv        
        if (fclose($file) === false) {
            $this->error = 1;
            $this->mensajes[] = "Error cerrando el archivo csv de catálogo para ".ucfirst($this->marketplace);   
                          
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Error cerrando el archivo csv de catálogo para ".ucfirst($this->marketplace).PHP_EOL, FILE_APPEND);

            return false;
        }        

        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Archivo csv de catálogo para ".ucfirst($this->marketplace).' generado en '.$this->csv_path.PHP_EOL, FILE_APPEND);
       
        return true;
    }  

    //función que busca los ids de un producto en la tabla lafrips_mirakl_ofertas para un marketplace con la condición de que esté activo. En principio dan igual los canales, ya que parece que un producto tiene la oferta activa en todos o ninguno (PCComponentes es un poco raro porque los canales son independientes y de moemento no podemos manejarlos) así que si está activo en alguno basta para saber que sus datos se actualizaron vía API con json
    public function checkOfertaActivaMArketplace($id_product, $id_product_attribute) {
        $sql_check_ofertas_activas = "SELECT id_mirakl_ofertas
        FROM lafrips_mirakl_ofertas
        WHERE active = 1
        AND marketplace = '".$this->marketplace."'
        AND id_product = $id_product         
        AND id_product_attribute = $id_product_attribute";  
        
        $check_ofertas_activas = Db::getInstance()->ExecuteS($sql_check_ofertas_activas);      

        if (!$check_ofertas_activas || count($check_ofertas_activas) < 1) {                       
            return false;
        } 
        
        return true;        
    }

    //función que calcula el pvp publicación de un producto para aquellos que van a entrar al csv pero no se encuentran en lafrips_mirakl_ofertas y por tanto no tenemos calculado sus pvps. pvp publicación es el pvp de prestashop + gastos de envío en función de destino (por país de canal, sacado de frik_amazon_reglas) + la comisión por marketplace
    //12/06/2024 Cambiamos pvp de publicación a pvp prestashop más gastos envío, quitamos comisión
    // CASE
    //     WHEN ((pro.price*((tax.rate/100)+1))*((mim.comision/100)+1)) > 30 THEN
    //         (((pro.price*((tax.rate/100)+1)) + are.coste_sign)*((mim.comision/100)+1))
    //     ELSE (((pro.price*((tax.rate/100)+1)) + are.coste_track)*((mim.comision/100)+1))
    // END
    //09/07/2024 Hay que integrar diferentes cambios de moneda como en frik_amazon_reglas, ya que hemos introducido el Marketplace Empik de Polonia y los pvp han de ir en su moneda. Utilizamos el campo cambio de frik_amazon_reglas *are.cambio
    public function getPvpPublicacion($id_product, $channel_code) {
        $sql_pvp_publicacion = "SELECT
        ROUND(
        CASE
            WHEN (pro.price*((tax.rate/100)+1)) > 30 THEN
                ((pro.price*((tax.rate/100)+1)) + are.coste_sign)
            ELSE ((pro.price*((tax.rate/100)+1)) + are.coste_track)
        END
        *are.cambio, 2)
        AS pvp_publicacion
        FROM lafrips_product pro        
        JOIN lafrips_tax_rule tar ON pro.id_tax_rules_group = tar.id_tax_rules_group AND tar.id_country = 6
        JOIN lafrips_tax tax ON tax.id_tax = tar.id_tax        
        JOIN lafrips_mirakl_marketplaces mim        
        LEFT JOIN lafrips_mirakl_channels mic ON mic.marketplace = mim.marketplace 
        JOIN frik_amazon_reglas are ON are.codigo = mic.iso
        WHERE mim.marketplace = '".$this->marketplace."'
        AND mic.channel_code = '".$channel_code."'
        AND pro.id_product = $id_product";

        if (!$pvp_publicacion = Db::getInstance()->getValue($sql_pvp_publicacion)) {
            $this->error = 1;
            $this->mensajes[] = "Error obteniendo pvp_publicacion para producto $id_product y canal ".$channel_code." para ".ucfirst($this->marketplace);   
                          
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Error obteniendo pvp_publicacion para producto $id_product y canal ".$channel_code." para ".ucfirst($this->marketplace).PHP_EOL, FILE_APPEND);

            return false;
        } else {
            return $pvp_publicacion;
        }
    }

    //función para buscar un producto en lafrips_mirakl_ofertas por sus ids y canal que si lo encuentra devuelve el pvp resultante del proceso de buybox, pvp_exportar
    //deveulve un array('exportar'=>xxx , 'publicacion'=> xxx) Si el pvp_exportado estuviera vacío en su lugar en el array se meterá también el valor de publicación
    //09/07/2024 Añadimos la condición a la SELECT del marketplace, ya que si no indicamos marketplace hay errores con canales con mismo nombre (INIT...)
    public function getPvps($id_product, $id_product_attribute, $channel) {
        $sql_select_pvps = "SELECT pvp_exportado, pvp_publicacion
        FROM lafrips_mirakl_ofertas
        WHERE channel = '".$channel."'
        AND marketplace = '".$this->marketplace."'
        AND id_product = $id_product
        AND id_product_attribute = $id_product_attribute";  

        $pvps = Db::getInstance()->getRow($sql_select_pvps);     

        if (!$pvps || count($pvps) < 1 || (!$pvps['pvp_exportado'] && !$pvps['pvp_publicacion'])) {
            //el producto puede que esté en la tabla, pero no para el marketplace o canal en proceso

            return false;
        } elseif (!$pvps['pvp_exportado'] && $pvps['pvp_publicacion'] > 0) {
            return array(
                "exportar" => $pvps['pvp_publicacion'],
                "publicacion" => $pvps['pvp_publicacion']
            );
        } else {
            return array(
                "exportar" => $pvps['pvp_exportado'],
                "publicacion" => $pvps['pvp_publicacion']
            );
        }
    }

    //función que recibe un pvp y un porcentaje (positivo o negativo) y aumenta o disminuye el pvp en ese porcentaje, y lo redondea a los siguientes 10 centimos (3.45 => 3.50, 2.11 => 2.20, 3.93 => 4) 
    public function modificaPVP($pvp, $porcentaje) {
        //el porcentaje de descuento puede ser positivo para subir el precio o negativo para bajarlo
        if ($porcentaje < 0) {
            // Discount scenario
            $pvp = $pvp * (1 - abs($porcentaje) / 100);
        } else {
            // Increase scenario
            $pvp = $pvp * (1 + $porcentaje / 100);
        }

        //ahora redondeamos para que quede en 10 centimos redondos, a no ser que sea entero
        //multiplicamos por 10 
        $priceInTenths = $pvp * 10;
    
        //redondeamos el resultado al siguiente entero
        $roundedPriceInTenths = ceil($priceInTenths);
    
        //convertimos el nuevo número a la escala original dividiendo por 10
        $roundedPrice = $roundedPriceInTenths / 10;
        
        //nos aseguramos de que tenga dos decimales
        $nuevo_pvp = number_format($roundedPrice, 2, '.', '');
    
        return $nuevo_pvp;
    }

    //función que limpia el servidor de archivos antiguos de log y prepara el de esta sesión
    public function setLog() {
        //cuando la hora de ejecución del proceso sea 05 buscaremos archivos con más de x días de antiguedad y los eliminaremos, de modo que se haga una vez al día.         
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
        $this->log_file = $this->log_path.'proceso_catalogo_marketplaces_mirakl_'.date('Y-m-d H:i:s').'.txt';
                   
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso para catálogos marketplaces Mirakl'.PHP_EOL, FILE_APPEND);       

        $sql_marketplaces_names = "SELECT marketplace FROM lafrips_mirakl_marketplaces WHERE active = 1";

        $marketplaces_names = Db::getInstance()->ExecuteS($sql_marketplaces_names);   
        $names = "";
        foreach ($marketplaces_names AS $marketplace_name) {
            $names .= ucfirst($marketplace_name['marketplace']).", ";
        }
        //quitamos la última coma
        $names = rtrim($names, ', ');

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.count($marketplaces_names).' marketplaces a procesar: '.$names.PHP_EOL, FILE_APPEND);   

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Proveedores seleccionados para procesar sin stock '.implode(',', $this->proveedores_sin_stock).PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Fabricantes seleccionados para no procesar '.implode(',', $this->fabricantes_evitar).PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Proveedores seleccionados para no procesar '.implode(',', $this->proveedores_evitar).PHP_EOL, FILE_APPEND);
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Categorías seleccionadas para no procesar '.implode(',', $this->categorias_evitar).PHP_EOL, FILE_APPEND);

        return;        
    }
       
    //obtiene los productos para gestionar. Hay que sacar los que tienen la categoría worten, obteniendo referencia, ean, pvp, por ahora no ponemos el pvp descuento pero lo sacamos en la sql, el stock, que si es producto sin stock pero con permitir pedido será 999, pero dependerá del marketplace si lo tenemos configurado. Por ahora sacamos el stock y si tiene permitir pedido por separado, de modo que según el marketplace, si no tiene stock pero si permitir pedido, pondremos 999 o 0
    //para el stock sacamos el stock online disponible, es decir , restamos el stock físico de tienda al quantity de stock_available
    //Vamos a poner diferentes tiempos leadtime_to_ship según el proveedor, dado que Redstring quizás da para envío en 4 días pero Noble son 5, de modo que como con Mirakl se pone leadtime_to_ship, que son los días de "preparación" sin contar el viaje del paquete, lo que haremos será restar 2 a lo que haya en la tabla de lafrips_mensaje_disponibilidad, que es el valor latency para amazon, que sería el tiempo desde el pedido hasta llegar al cliente. Redstring tiene 4, queda en 2, Noble tiene 5, queda en 3.
    // Buscamos en la tabla mensaje_disponibilidad y restamos 2 a lo que haya, usando 7 si el proveedor no está en la tabla. Lo sacamos para cada producto según su proveedor, y si luego es necesario se lo pondremos al csv
    // IFNULL(med.latency, 7) AS latency
    //sku;product-id;product-id-type;price;quantity;state;available-start-date;available-end-date;discount-price;discount-start-date;discount-end-date;leadtime-to-ship;strike-price-type;canon;tipo-iva;update-delete;
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

        //09/05/2024 voy a rellenar con 0 a la izquierda los ean hasta 13 cifras LPAD(IFNULL(pat.ean13, pro.ean13), 13, 0) AS 'product-id',
        // quitamos lo de que solo coja los de una categoría (worten a 09/05/2024)
        // AND pro.id_product IN (SELECT id_product FROM lafrips_category_product WHERE id_category = ".$this->categoria_origen.")
        //05/06/2024 buscamos si cada producto se encuentra en la tabla lafrips_mirakl_ofertas, independientemente del marketplace etc, para limitar la búsqueda del producto a la hora de sacar el pvp a solo los que sabemos que están
        $sql_productos = "SELECT 
        IF((SELECT COUNT(*) FROM lafrips_mirakl_ofertas WHERE id_product = ava.id_product AND id_product_attribute = ava.id_product_attribute) > 0, 1, 0) AS activo_mirakl,
        ava.id_product AS id_product, ava.id_product_attribute AS id_product_attribute,
        pro.id_supplier AS id_supplier, IFNULL(pat.reference, pro.reference) AS sku, LPAD(IFNULL(pat.ean13, pro.ean13), 13, 0) AS 'product-id',
        ROUND(pro.price*((tax.rate/100)+1),2) AS price,
        CASE 
            WHEN spp.reduction_type = 'percentage' THEN ROUND(((pro.price*((tax.rate/100)+1)) - (pro.price*((tax.rate/100)+1) * spp.reduction)),2)	
            WHEN spp.reduction_type = 'amount'  THEN ROUND(((pro.price*((tax.rate/100)+1)) - spp.reduction),2)
        ELSE ''
        END
        AS 'discount-price',       
        ROUND(tax.rate, 0) AS 'tipo-iva',
        ava.out_of_stock AS permite_pedido_sin_stock,
        (ava.quantity - IFNULL((SELECT SUM(physical_quantity) FROM lafrips_stock 
			WHERE id_product = ava.id_product AND id_product_attribute = ava.id_product_attribute AND id_warehouse = 4),0)) AS quantity,
        (IFNULL(med.latency, 7) - 2) AS supplier_leadtime_to_ship     
        FROM lafrips_stock_available ava
        JOIN lafrips_product pro ON pro.id_product = ava.id_product          
        LEFT JOIN lafrips_product_attribute pat ON pat.id_product = ava.id_product AND pat.id_product_attribute = ava.id_product_attribute
        JOIN lafrips_tax_rule tar ON pro.id_tax_rules_group = tar.id_tax_rules_group AND tar.id_country = 6
        JOIN lafrips_tax tax ON tax.id_tax = tar.id_tax   
        LEFT JOIN lafrips_mensaje_disponibilidad med ON med.id_supplier = pro.id_supplier AND med.id_lang = 1     
        LEFT JOIN lafrips_specific_price spp ON pro.id_product =  spp.id_product
            AND spp.from_quantity = 1    
            AND spp.id_specific_price_rule = 0
            AND spp.id_customer = 0
            AND spp.to = '0000-00-00 00:00:00' #evitamos descuentos pasados, no apareceran los temporales
        WHERE 1
        ".$evitar_fabricantes."
        ".$evitar_proveedores."
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
            
            return false;
        } 
                      
        $this->mensajes[] = "Productos obtenidos para exportar/sincronizar con marketplaces = ".count($this->productos);  
            
        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Productos obtenidos para exportar/sincronizar con marketplaces = ".count($this->productos).PHP_EOL, FILE_APPEND); 
                
        return true;
    }          

    public function getCredentials() {
        //Obtenemos la key leyendo el archivo mirakl_marketplace_credentials.json donde hemos almacenado url y api_key para cada marketplace
        $secrets_json = file_get_contents(dirname(__FILE__).'/../secrets/mirakl_marketplace_credentials.json');

        if ($secrets_json == false) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error obteniendo credenciales para marketplaces, abortando proceso'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes[] = ' - Error obteniendo credenciales para marketplaces, abortando proceso'; 

            return false;
        }

        // echo '<br><br>worten: '.$secrets['worten']['url'];
        // echo '<br><br>worten: '.$secrets['worten']['shop_key'];
        // echo '<br><br>pccomponentes: '.$secrets['pccomponentes']['url'];
        // echo '<br><br>pccomponentes: '.$secrets['pccomponentes']['shop_key'];
        // echo '<br><br>mediamarkt: '.$secrets['mediamarkt']['url'];
        // echo '<br><br>mediamarkt: '.$secrets['mediamarkt']['shop_key'];
        
        //almacenamos decodificado como array asociativo (segundo parámetro true, si no sería un objeto)
        $this->marketplaces_credentials = json_decode($secrets_json, true); 

        return true;        
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

        $asunto = 'ERROR actualizando catálogos para marketplaces Mirakl '.date("Y-m-d H:i:s");
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


