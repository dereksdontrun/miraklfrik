<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');

//https://lafrikileria.com/tareas_programadas/mirakl/proceso/MiraklOfertasActivas.php
//https://lafrikileria.com/test/tareas_programadas/mirakl/proceso/MiraklOfertasActivas.php


//https://lafrikileria.com/modules/miraklfrik/classes/MiraklOfertasActivas.php?cron=true

//22/05/2024 Proceso para descargar un csv o json con las ofertas activas de cada marketplace y canal, es decir, solo productos activos - con stock en las plataformas. Estos productos se almacenarán o actualizarán en la tabla lafrips_mirakl_ofertas para después revisar cada uno con otra API obteniendo todos sus vendedores en la plataforma, y en función del PVP más barato, almacenar un posible PVP nuestro para la BuyBox. Después el proceso que exporta los productos con su stock y precio consultará esa tabla para enviar dicho precio que pueda ganar la BuyBox
//solicitamos los productos activos con la API OFF52 la cual es asincrona y devuelve un tracking_id para después pedirlo con la API OFF53. Como no se puede hacer seguido ya que no le da tiempo a procesar, primero almacenamos los tracking_id para todos los marketplaces en el array $marketplace_configuration (ya no se usa, guardamos en un array para trackings), después pararemos 10 segundos antes de solicitar la descarga. La API tampoco permite pedir para cada canal seguido por "too many requests" pero como casi seguro que los productos activos son los mismos en todos los canales de cada marketplace,haremos solo una petición por marketplace, auqnue a la hora de procesar precios lo tengamos que hacer por canal y por tanto insertar una línea por canal y producto en lafrips_mirakl_ofertas

//30/05/2024 tenemos que calcular el pvp_minimo al que podemos poner el producto en el moemnto de bajar las ofertas activas. Para ello tengo que saber si el producto es outlet, tipo C, venta sin stock o normal, lo que nos da un porcentaje mínimo. El porcentaje minimo para cada tipo esta en la tabla lafrips_reglas_amazon. Además otro porcetaje de 15% de comisión, por ahora, más el 21% iva, más preparación, que está guardado en tabla configuration Configuration::get('COSTE_PREPARACION_PRODUCTO') más los gastos de envío (si pvp minimo hasta aqui sale > 30 coste sign, si no coste tracked)
// ((pro.wholesale_price*((tax.rate/100)+1) * (((are.margen_minimo_sin_stock + comision_marketplace)/100) + 1)) + $preparacion + gastos_envio)
//esto se podría hacer al sacar los pvps de otros vendedores o aquí haciendo un loop por cada canal de  marketplace. Los paises son Mediamarkt a España, Worten a Espeaña o Portugal, Pccomponentes a todos esos paises, todos existen en lafips_reglas amazon

//31/05/2024 Pasamos a utilizar las tablas lafrips_mirakl_marketplaces y lafrips_mirakl_channels para la info de marketplaces etc, en lugar del array $marketplace_configuration de modo que no haya que estar actualizando el array en cada proceso programado. Aquí crearemos un array para guardar el tracking_id de cada marketplace mientras estamos ejecutando 

//03/06/2024 Vamos a calcular el pvp mínimo e insertarlo con el producto cada vez que bajemos los productos activos, de modo que lo tengmaos disponible para cuando procesemos las ofertas para calcular la buy box

//25/06/2024 Metemos la clase en módulo miraklfrik

//09/07/2024 Hay que integrar diferentes cambios de moneda como en frik_amazon_reglas, ya que hemos introducido el Marketplace Empik de Polonia y los pvp han de ir en su moneda.

// ini_set('error_log', _PS_ROOT_DIR_.'/modules/miraklfrik/log/error/php_error.log');

// // Turn on error reporting
// ini_set('display_errors', 1);
// // error_reporting(E_ALL); cambiamos para que no saque E_NOTICE
// error_reporting(E_ERROR | E_WARNING | E_PARSE | E_DEPRECATED | E_STRICT);

if (isset($_GET['cron']) && $_GET['cron'] == 'true') {
    $a = new MiraklOfertasActivas();
} else {
    exit;
}

class MiraklOfertasActivas
{
    //variable donde guardaremos los productos que están en tabla lafrips_mirakl_ofertas para un marketplace y canal para comparar con lo que nos devuelve la API como productos activos
    public $productos_tabla_mirakl_ofertas = array();    

    //aquí guardaremos el resultado de la solictud del JSON con los productos activos de un marketplace y canal
    public $productos_activos;  
    
    public $sku_prestashop; 

    public $sku_mirakl; 

    public $id_product;
    public $id_product_attribute;

    //variable que contendrá los id_supplier de proveedores que entran en el proceso para productos sin stock. Almacenados en 'PROVEEDORES_VENTA_SIN_STOCK' en lafrips_caonfiguration
    // Cerdá - 65, Karactermanía - 53, 24 - Redstring, 8 - Erik, 121 - Distrineo, 111 - Noble 
    public $proveedores_sin_stock;    

    public $mensajes = array();

    public $error = 0;  

    public $contador_activos = 0;

    public $contador_insertados = 0;

    public $log = true;    

    //para cuando aún no hay productos en la tabla ponemos una variable test, si su valor es true no tendrá en cuenta que no hay productos para la búsqueda
    public $test = true;

    //variable para el archivo a generar en el servidor con las líneas log
    public $log_file;   

    //carpeta de archivos log    
    public $log_path = _PS_ROOT_DIR_.'/modules/miraklfrik/log/ofertas_activas/';       

    //para almacenar las credenciales para la conexión a la API según el Marketplace. Tendrá formato array($end_point, $api_key)
    //por ahora también meteré algunas variables por marketplace, por ejemplo, si exportar productos con venta sin stock o no, etc
    public $marketplaces_credentials = array();    

    //variables donde se almacena el marketplace que estamos procesando, su out_of_stock, su modificacion_pvp
    public $marketplace;
    public $channel;
    public $channel_active;    
    public $end_point;
    public $shop_key;
    public $tracking_id;
    public $url_json;

    //31/05/2024 variable para guardar la info de marketplaces que sacaremos de lafrips_mirakl_marketplaces en lugar de utilizar el array de debajo $marketplace_configuration
    public $marketplaces;
    public $marketplace_channels;

    //array con los posibles parámetros necesarios por cada marketplace, por ejemplo, out_of_stock 1 o 0 indicando si enviamos los de permitir pedido con stock o no, campos específicos que deben ir en el csv a exportar solo para ese marketplace, etc. En este proceso no se utiliza todo
    //añado los canales de cada marketplace
    //31/05/2024 Pasamos a utilizar las tablas lafrips_mirakl_marketplaces y lafrips_mirakl_channels para la info de marketplaces etc, en lugar del array $marketplace_configuration de modo que no haya que estar actualizando el array en cada proceso programado. Aquí crearemos un array para guardar el tracking_id de cada marketplace mientras estamos ejecutando. Los meteremos al vuelo, tanto nombre de marketplace como tracking_id, de modo que lo declaramos vacío
    public $marketplaces_tracking_ids = array();
              

    public function __construct() {    

        date_default_timezone_set("Europe/Madrid");

        //preparamos log        
        $this->setLog();   

        if (!$this->getCredentials()) {
            $this->enviaEmail();

            exit;
        }              

        //llamamos a función que pedirá los productos para cada marketplace. Aquí pediremos el tracking_id para el informe de cada marketplace
        $this->solicitaProductosActivosMarketplaces();        
        
        //hacemos una pausa de 10 segundos para dar tiempo a Mirakl a procesar los informes de productos activos solicitados
        $delay = 10;
        sleep($delay);
        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Pausa de $delay segundos realizada para permitir proceso de informes solicitados".PHP_EOL, FILE_APPEND); 

        //antes de procesar los productos sacamos los id_supplier de proveedores sin stock permitidos para marketplaces, almacenados en lafrips_configuration 
        $this->proveedores_sin_stock = explode(",", Configuration::get('PROVEEDORES_VENTA_SIN_STOCK'));
        
        //llamamos a función que procesará los productos para cada marketplace. Aquí comprobaremos y descargaremos cada informe de productos activos en JSON para cada tracking_id obtenido en el paso anterior
        $this->procesaProductosActivosMarketplaces();  

        $this->mensajes[] = "Proceso de descarga de productos activos de marketplaces terminado";         
            
        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Proceso de descarga de productos activos de marketplaces terminado".PHP_EOL, FILE_APPEND);  

        echo "Proceso de descarga de productos activos de marketplaces terminado";

        if ($this->error) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - PROCESO FINALIZADO CON ERRORES".PHP_EOL, FILE_APPEND);   

            $this->enviaEmail();
        }
        
    }    

    //función que hace un bucle recorriendo los marketplaces a procesar y solicita los productos activos de cada uno llamando a la función apiOFF52ProductosActivos()
    //solicitamos los productos activos con la API OFF52 la cual es asincrona y devuelve un tracking_id para después pedirlo con la API OFF53. Como no se puede hacer seguido ya que no le da tiempo a procesar, primero almacenamos los tracking_id para todos los marketplaces en el array $marketplace_tracking_ids, después pararemos 10 segundos antes de solicitar la descarga. La API tampoco permite pedir para cada canal seguido por "too many requests" pero como casi seguro que los productos activos son los mismos en todos los canales de cada marketplace,haremos solo una petición por marketplace, aunque a la hora de procesar precios lo tengamos que hacer por canal y por tanto insertar una línea por canal y producto en lafrips_mirakl_ofertas
    public function solicitaProductosActivosMarketplaces() {
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
            $this->marketplace = $marketplace['marketplace'];          

            //url endponit y shop_key sacamos de credentials            
            $this->end_point = $this->marketplaces_credentials[$this->marketplace]['url'];

            $this->shop_key = $this->marketplaces_credentials[$this->marketplace]['shop_key'];

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Marketplace '.ucfirst($this->marketplace).' - Solicitud productos activos:'.PHP_EOL, FILE_APPEND);  

            //pedimos los productos activos del marketplace, almacenamos el tracking_id en array $markeplace_configuration
            if (!$this->apiOFF52ProductosActivos()) {
                //error con proceso APIs, pasamos a siguiente canal                
                continue;
            }   
            
            //guardamos el tracking_id
            $this->marketplaces_tracking_ids[$this->marketplace] = $this->tracking_id;            
            
        }

        return;
    }

    //función que hace un bucle recorriendo los marketplaces a procesar y solicita el informe de los productos activos de cada uno, que hemos pedido en el paso anterior, para después insertar/actualizar en la tabla lafrips_mirakl_ofertas. Tenemos el tracking_id de cada petición por marketplace
    //31/05/2024 Dejamos de usar el array $this->marketplace_configuration, el tracking_id está almacenado en $this->marketplaces_tracking_ids por su nombre de marketplace
    public function procesaProductosActivosMarketplaces() {
        foreach ($this->marketplaces_tracking_ids AS $key => $value) {
            //nos aseguramos de resetear esta variable para cada petición
            $this->tracking_id = false;
            $this->contador_insertados = 0;
            $this->contador_activos = 0;
            
            //preparamos las variables del marketplace
            $this->marketplace = $key;            

            $this->tracking_id = $value;

            //url endponit y shop_key sacamos de credentials            
            $this->end_point = $this->marketplaces_credentials[$this->marketplace]['url'];

            $this->shop_key = $this->marketplaces_credentials[$this->marketplace]['shop_key'];            

            //sacamos los canales activos para el marketplace para saber las líneas a insertar en tabla por producto
            $sql_channels = "SELECT channel_code FROM lafrips_mirakl_channels WHERE active = 1 AND marketplace = '".$this->marketplace."'";

            $this->marketplace_channels = Db::getInstance()->ExecuteS($sql_channels);      

            if (!$this->marketplace_channels || !is_array($this->marketplace_channels) || count($this->marketplace_channels) < 1) {
                $this->error = 1;
                $this->mensajes[] = "No se pudo obtener la información de los canales activos para marketplace ".$this->marketplace." desde la Base de Datos";            

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - No se pudo obtener la información de los canales activos para marketplace ".$this->marketplace." desde la Base de Datos'.PHP_EOL, FILE_APPEND);     
                
                //pasamos a siguiente marketplace
                continue;
            } 

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Marketplace '.ucfirst($this->marketplace).' - Comprobación informe productos activos:'.PHP_EOL, FILE_APPEND);  

            //pedimos la comprobación del tracking_id y si es COMPLETED almacenaremos la url del json a descargar
            if (!$this->apiOFF53StatusProductosActivos()) {
                continue;
            }

            //pedimos la descarga del json y guardamos el informe en $this->productos_activos
            if (!$this->apiOFF54GeTProductosActivos()) {
                continue;
            }  

            //ponemos active a 0 en los productos del marketplace
            if (!$this->resetMiraklOfertas()) {
                continue;
            } 

            //procesamos el JSON de productos activos que ha sido pasado a objeto, $this->productos_activos, comparando con tabla lafrips_mirakl_ofertas
            if (!$this->procesaProductos()) {
                //error procesando productos para tabla, pasamos a siguiente marketplace                
                continue;
            } 

            

        }

        return;
    }

    //función que compara objeto $this->productos_activos con tabla lafrips_mirakl_ofertas para saber, dado un marketplace, qué productos activos están en la tabla, marcándolos active si los encuentra, para todos sus canales o insertándolos, también para todos sus canales
    public function procesaProductos() {
        $this->sku_prestashop = null; 
        $this->sku_mirakl = null; 
     
        $contador = 0;
        //recorremos los productos de $this->productos_activos sacando sus sku
        foreach ($this->productos_activos AS $producto_activo) {
            $this->id_product = null;
            $this->id_product_attribute = null;

            // if ($this->test && $contador > 500) {
            //     break;
            // }

            $this->sku_prestashop = $producto_activo->shop_sku;
            $this->sku_mirakl = $producto_activo->product_sku;
            
            //llamamos a checkProductoTabla() para comprobar si el producto se encuentra en la tabla para este marketplace, si lo está haemos update active = 1 para todos los canales del marketplace, si no está lo insertamos con insertTabla()
            //calculamos el pvp mínimo y de publicación en ambos casos
            if ($this->checkProductoTabla() == false) {
                //el producto no está en la tabla, lo insertamos
                if ($this->insertTabla()) {
                    $this->contador_insertados++;
                    
                    //está en la tabla, sacamos pvp mínimo y de publicación
                    $this->pvpMinimoYPublicacion();    
                }   
                                
            } else {
                //está en la tabla, sacamos pvp mínimo y de publicación
                $this->pvpMinimoYPublicacion();    
            }

            $this->contador_activos++;                            

            $contador++;

            continue;
        }

        $this->mensajes[] = 'Productos insertados Marketplace '.ucfirst($this->marketplace).' = '.$this->contador_insertados;  
        $this->mensajes[] = 'Productos activos Marketplace '.ucfirst($this->marketplace).' = '.$this->contador_activos;         
            
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Productos insertados Marketplace '.ucfirst($this->marketplace).' = '.$this->contador_insertados.PHP_EOL, FILE_APPEND);  
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Productos activos Marketplace '.ucfirst($this->marketplace).' = '.$this->contador_activos.PHP_EOL, FILE_APPEND);  

        return true;
    }

    //función que calcula el pvp mínimo para un producto que se utilizará para el calculo de la buy box, y el precio de publicación, que es el pvp deseado y consiste en el pvp de prestashop, más gastos de envío (signed o tracked) más la comisión del marketplace.
    //pvp mínimo es el pvp más bajo que podemos aplicar a un producto cuando buscamos la buy box sin "perder" dinero. Primero se calcula un pvp sin contar con gastos de envío, ya que si supera una cantidad (30€) se aplicará un transporte y si no llega otro (gastos de envío de Spring Signed de lafrips_reglas_amazon, o tracked si es menos de 30). Consiste en el precio de coste del producto, con el iva, más un porcentaje fijo o margen mínimo de "beneficio" que depende de si el producto es outlet, tipo C, venta sin stock o normal, que está en la tabla lafrips_reglas_amazon, más un coste de preparación que se encuentra en lafrips_configuration con name 'COSTE_PREPARACION_PRODUCTO', y a todo eso se le aplica un porcentaje de comisión para la plataforma que está en lafrips_mirakl_marketplaces. Si este valor pasa o no de 30 se hará el mismo calculo pero añadiendo los gastos de envío que toquen junto al gastop de preparación y a todo volver a calcularle el porcentaje de comisión, y eso será el pvp mínimo, que a veces puede ser superior al pvp en prestashop.
    //esto implica que, teniendo los ids de Prestashop del producto, tengo que averiguar todas estas cosas
    // ((pro.wholesale_price*((tax.rate/100)+1) * (((are.margen_minimo + comision_marketplace)/100) + 1)) + $preparacion + gastos_envio)
    //12/06/2024 Cambiamos pvp de publicación a pvp prestashop más gastos envío, quitamos comisión
    // CASE
    //     WHEN ((pro.price*((tax.rate/100)+1))*((mim.comision/100)+1)) > 30 THEN
    //         (((pro.price*((tax.rate/100)+1)) + are.coste_sign)*((mim.comision/100)+1))
    //     ELSE (((pro.price*((tax.rate/100)+1)) + are.coste_track)*((mim.comision/100)+1))
    // END
    public function pvpMinimoYPublicacion() {
        //en este punto los ids de producto deben estar almacenados
        if (is_null($this->id_product) || is_null($this->id_product_attribute)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error obteniendo PVP Mínimo para marketplace '.$this->marketplace.' producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.', ids de producto nulos'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error obteniendo PVP Mínimo para marketplace '.$this->marketplace.' producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.', ids de producto nulos';

            return;
        }

        //necesitamos saber de que canales del marketplace en proceso hay que sacar el pvp minimo, ya que depende del pais del canal los gastos de envío. Tenemos que obtener el código iso (ES, PT, DE etc) del canal para buscar en frik_reglas_amazon 
        //09/07/2024 tenemos en cuenta el cambio en frik_reglas_amazon, para monedas como PLN de Polonia...
        $sql_select_channels = "SELECT id_mirakl_ofertas, channel 
        FROM lafrips_mirakl_ofertas 
        WHERE marketplace = '".$this->marketplace."'
        AND id_product = ".$this->id_product."
        AND id_product_attribute = ".$this->id_product_attribute;

        if (!$channels = Db::getInstance()->executeS($sql_select_channels)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error obteniendo canales de lafrips_mirakl_ofertas para PVP Mínimo para marketplace '.$this->marketplace.' producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error obteniendo canales de lafrips_mirakl_ofertas para PVP Mínimo para marketplace '.$this->marketplace.' producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl;

            return;
        }

        foreach ($channels AS $channel) {
            //obtenemos el pvp minimo por canal 
            $sql_pvp_minimo_y_publicacion = "SELECT
            ROUND(
            CASE
                WHEN (pro.price*((tax.rate/100)+1)) > 30 THEN
                    ((pro.price*((tax.rate/100)+1)) + are.coste_sign)
                ELSE ((pro.price*((tax.rate/100)+1)) + are.coste_track)
            END
            *are.cambio , 2)
            AS pvp_publicacion,
            ROUND(
            CASE #case para saber si es sin stock, outlet, c o 'normal'
                WHEN (ava.quantity <= 0 AND pro.id_supplier IN (".implode(',', $this->proveedores_sin_stock).") AND ava.out_of_stock = 1) THEN (
                    CASE 
                    WHEN ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo_sin_stock/100) + 1) + conf.value))* ((mim.comision/100)+1)) > 30 THEN 
                        ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo_sin_stock/100) + 1) + conf.value + are.coste_sign))* ((mim.comision/100)+1)) 
                    ELSE ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo_sin_stock/100) + 1) + conf.value + are.coste_track))* ((mim.comision/100)+1)) 
                    END
                ) #fin case es sin stock con permitir pedido
                WHEN (SELECT id_product FROM lafrips_category_product WHERE id_category = 319 AND id_product = pro.id_product) THEN (
                    CASE 
                    WHEN ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo_outlet/100) + 1) + conf.value))* ((mim.comision/100)+1)) > 30 THEN 
                        ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo_outlet/100) + 1) + conf.value + are.coste_sign))* ((mim.comision/100)+1)) 
                    ELSE ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo_outlet/100) + 1) + conf.value + are.coste_track))* ((mim.comision/100)+1)) 
                    END
                ) #fin case es outlet
                WHEN (con.abc = 'C' OR con.consumo IS NULL) THEN (
                    CASE 
                    WHEN ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo_c/100) + 1) + conf.value))* ((mim.comision/100)+1)) > 30 THEN 
                        ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo_c/100) + 1) + conf.value + are.coste_sign))* ((mim.comision/100)+1))
                    ELSE ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo_c/100) + 1) + conf.value + are.coste_track))* ((mim.comision/100)+1))
                    END
                ) #fin case es C
                ELSE (
                    CASE 
                    WHEN ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo/100) + 1) + conf.value))* ((mim.comision/100)+1)) > 30 THEN 
                        ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo/100) + 1) + conf.value + are.coste_sign))* ((mim.comision/100)+1)) 
                    ELSE ((((pro.wholesale_price*((tax.rate/100)+1)) * ((are.margen_minimo/100) + 1) + conf.value + are.coste_track))* ((mim.comision/100)+1))
                    END
                ) #fin case no es outlet ni C ni sin stock       
            END
            *are.cambio , 2)
            AS 'pvp_minimo'
            FROM lafrips_product pro
            JOIN lafrips_stock_available ava ON pro.id_product = ava.id_product
            JOIN lafrips_tax_rule tar ON pro.id_tax_rules_group = tar.id_tax_rules_group AND tar.id_country = 6
            JOIN lafrips_tax tax ON tax.id_tax = tar.id_tax
            JOIN lafrips_configuration conf ON conf.name = 'COSTE_PREPARACION_PRODUCTO'
            JOIN lafrips_mirakl_marketplaces mim  
            LEFT JOIN lafrips_consumos con ON con.id_product = ava.id_product AND con.id_product_attribute = ava.id_product_attribute
            LEFT JOIN lafrips_mirakl_ofertas mio ON mio.id_product = ava.id_product 
                AND mio.id_product_attribute = ava.id_product_attribute
                AND mio.marketplace = mim.marketplace    
            LEFT JOIN lafrips_mirakl_channels mic ON mic.marketplace =  mim.marketplace AND mic.channel_code = mio.channel
            JOIN frik_amazon_reglas are ON are.codigo = mic.iso
            WHERE mim.marketplace = '".$this->marketplace."'
            AND mio.channel = '".$channel['channel']."'
            AND ava.id_product = ".$this->id_product."
            AND ava.id_product_attribute = ".$this->id_product_attribute;

            $pvp_minimo_y_publicacion = Db::getInstance()->getRow($sql_pvp_minimo_y_publicacion);       

            if (!$pvp_minimo_y_publicacion['pvp_publicacion'] || $pvp_minimo_y_publicacion['pvp_publicacion'] == 0 || !$pvp_minimo_y_publicacion['pvp_minimo'] || $pvp_minimo_y_publicacion['pvp_minimo'] == 0) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error calculando PVP Mínimo y de publicación para marketplace '.$this->marketplace.', canal '.$channel['channel'].', producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.PHP_EOL, FILE_APPEND); 
    
                $this->error = 1;
            
                $this->mensajes[] = ' - Error calculando PVP Mínimo y de publicación para marketplace '.$this->marketplace.', canal '.$channel['channel'].', producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl;

                continue;
            } else {
                //si el pvp_minimo queda superior al pvp_publicacion los igualamos y ponemos pvp_publicacion en ambos campos
                if ($pvp_minimo_y_publicacion['pvp_minimo'] > $pvp_minimo_y_publicacion['pvp_publicacion']) {
                    $pvp_minimo_y_publicacion['pvp_minimo'] = $pvp_minimo_y_publicacion['pvp_publicacion'];
                }

                $update_pvp_minimo = "UPDATE lafrips_mirakl_ofertas 
                SET pvp_minimo = ".$pvp_minimo_y_publicacion['pvp_minimo'].",
                pvp_publicacion = ".$pvp_minimo_y_publicacion['pvp_publicacion']."
                WHERE id_mirakl_ofertas = ".$channel['id_mirakl_ofertas'];

                if (!Db::getInstance()->execute($update_pvp_minimo)) {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error haciendo update pvp mínimo y de publicación en marketplace '.$this->marketplace.', canal '.$channel['channel'].', para producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
            
                    $this->error = 1;
                
                    $this->mensajes[] = ' - Error haciendo update pvp mínimo y de publicación en marketplace '.$this->marketplace.', canal '.$channel['channel'].', para producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas';
                }

                continue;
            }
        }

        return;
    }

    //función para insertar en tabla lafrips_mirakl_ofertas un producto que viene como activo en el marketplace pero no está en la tabla, una por producto, marketplace y canal
    public function insertTabla() {    
        //primero tenemos que obtener los datos del producto a insertar, id_product, id_product_attribute y nombre
        if (!$this->getIdsProducto()) {
            return false;
        }    

        //con los ids sacamos el nombre con atributos si los tiene
        if (!$nombre_producto = $this->getNombreProducto()) {
            return false;
        }
        
        // $sql_insert = "INSERT INTO lafrips_mirakl_ofertas 
        // (channel, marketplace, id_product, id_product_attribute, sku_prestashop, sku_mirakl, active, last_date_active, nombre, date_add) 
        // SELECT channel_code, '".$this->marketplace."', ".$this->id_product.", ".$this->id_product_attribute.", '".$this->sku_prestashop."', '".$this->sku_mirakl."', 1, NOW(), '".pSQL($nombre_producto)."', NOW() 
		// FROM lafrips_mirakl_channels WHERE marketplace = '".$this->marketplace."'";

        //insertamos una línea por producto y canal del marketplace
        foreach ($this->marketplace_channels AS $channel) {
            $sql_insert = "INSERT INTO lafrips_mirakl_ofertas 
            (marketplace, channel, id_product, id_product_attribute, sku_prestashop, sku_mirakl, active, last_date_active, nombre, date_add) 
            VALUES
            ('".$this->marketplace."', '".$channel['channel_code']."', ".$this->id_product.", ".$this->id_product_attribute.", '".$this->sku_prestashop."', '".$this->sku_mirakl."', 1, NOW(), '".pSQL($nombre_producto)."', NOW())";

            if (!Db::getInstance()->execute($sql_insert)) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error insertando para marketplace '.$this->marketplace.', canal '.$channel['channel_code'].' producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
        
                $this->error = 1;
            
                $this->mensajes[] = ' - Error insertando para marketplace '.$this->marketplace.', canal '.$channel['channel_code'].' producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas';                
            }

            continue;
        }              			        

        return true;
    }

    public function getNombreProducto() {
        $sql_nombre = "SELECT IFNULL(CONCAT(pla.name, ' : ', GROUP_CONCAT(DISTINCT agl.name, ' - ', atl.name ORDER BY agl.name SEPARATOR ', ')), pla.name) AS nombre
        FROM lafrips_stock_available ava
        JOIN lafrips_product_lang pla 
            ON pla.id_product = ava.id_product AND pla.id_lang = 1
        LEFT JOIN lafrips_product_attribute pat 
            ON pat.id_product = pla.id_product AND ava.id_product_attribute = pat.id_product_attribute
        LEFT JOIN lafrips_product_attribute_combination pac 
            ON pac.id_product_attribute = pat.id_product_attribute
        LEFT JOIN lafrips_attribute att 
            ON att.id_attribute = pac.id_attribute
        LEFT JOIN lafrips_attribute_lang atl 
            ON atl.id_attribute = pac.id_attribute AND atl.id_lang = 1
        LEFT JOIN lafrips_attribute_group_lang agl 
            ON agl.id_attribute_group = att.id_attribute_group AND agl.id_lang = 1
        WHERE pla.id_product = ".$this->id_product." AND ava.id_product_attribute = ".$this->id_product_attribute;

        //en teoría no puede haber ya errores en los ids así que debe corresponder a un solo producto nombre
        if (!$nombre = Db::getInstance()->executeS($sql_nombre)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error obteniendo el nombre de producto para sku_prestashop '.$this->sku_prestashop.PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error obteniendo el nombre de producto para sku_prestashop '.$this->sku_prestashop;   
            $this->mensajes[] = ' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas';

            return false;
        }

        return $nombre[0]['nombre'];
    }

    //función que con la sku_prestashop devuelve los datos de un producto, id_product, id_product_attribute
    public function getIdsProducto() {        
        //como es muy lento buscar en product y product_attribute por la cadena de reference buscamos en las tablas por separado
        //primero buscamos en lafrips_product
        $sql_product = "SELECT id_product
        FROM lafrips_product       
        WHERE reference = '".$this->sku_prestashop."'";
        
        $product = Db::getInstance()->executeS($sql_product);       

        //si devuelve más de un resultado puede ser error porque haya varios productos con la misma referencia o puede ser que tenemos la referencia base de un producto con atributos, en cuyo caso nos devuelve los datos de todos los atributos, en ambos casos es error y hay que señalarlo
        if (count($product) > 1) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, sku_prestashop '.$this->sku_prestashop.' corresponde a '.count($product).' productos como referencia base'.PHP_EOL, FILE_APPEND);            
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error, sku_prestashop '.$this->sku_prestashop.' corresponde a '.count($product).' productos como referencia base';            
            $this->mensajes[] = ' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas';

            return false;
        } elseif (count($product) < 1) {
            //la buscamos en lafrips_product_attribute
            $sql_product_attribute = "SELECT id_product, id_product_attribute
            FROM lafrips_product_attribute       
            WHERE reference = '".$this->sku_prestashop."'";
            
            $product_attribute = Db::getInstance()->executeS($sql_product_attribute);

            if (count($product_attribute) > 1) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, sku_prestashop '.$this->sku_prestashop.' corresponde a '.count($product_attribute).' productos como referencia de atributo'.PHP_EOL, FILE_APPEND);            
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
        
                $this->error = 1;
            
                $this->mensajes[] = ' - Error, sku_prestashop '.$this->sku_prestashop.' corresponde a '.count($product_attribute).' productos como referencia de atributo';            
                $this->mensajes[] = ' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas';
    
                return false;
            } elseif (count($product_attribute) < 1) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, sku_prestashop '.$this->sku_prestashop.' no se encuentra en base de datos de Prestashop'.PHP_EOL, FILE_APPEND);            
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
        
                $this->error = 1;
            
                $this->mensajes[] = ' - Error, sku_prestashop '.$this->sku_prestashop.' no se encuentra en base de datos de Prestashop';            
                $this->mensajes[] = ' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas';

                return false;
            } else {
                //se ha encontrado una sola correspondencia. tenemos (id_product, id_product_attribute)
                $this->id_product = $product_attribute[0]['id_product'];
                $this->id_product_attribute = $product_attribute[0]['id_product_attribute'];

                return true;
            }
            
        } else {
            //tenemos un id_product de lafrips_product, el id_product_attribute debe ser 0. 
            $this->id_product = $product[0]['id_product'];
            $this->id_product_attribute = 0;

            return true;            
        }
    }

    //función que pone active 0 a todos los producots de un marketplace en lafrips_mirakl_ofertas. La consulta pondrá a active = 0 los que estén en active = 1, poniendo la fecha de "desactivación", de modo que si ya no se activan, esa ser´su última fecha de desactivación
    public function resetMiraklOfertas() {
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Reseteando active a 0 para los productos del Marketplace '.$this->marketplace.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 

        $sql_reset = "UPDATE lafrips_mirakl_ofertas 
		SET 
        active = 0,
        last_date_deactivated = NOW()
        WHERE active = 1
        AND marketplace = '".$this->marketplace."'";

        if (!Db::getInstance()->execute($sql_reset)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error reseteando active a 0 para los productos del Marketplace '.$this->marketplace.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;

            $this->mensajes[] = ' - Error reseteando active a 0 para los productos del Marketplace '.$this->marketplace.' en tabla lafrips_mirakl_ofertas';

            return false;
        }

        return true;
    }    

    //función que busca una sku de mirakl en lafrips_mirakl_ofertas para un marketplace y si la encuentra pone active = 1 , recoge los ids de producto y devuelve true, si no  encuentra devuelve false. 
    //19/06/2024 Si añadimos un canal después de que ya hubieramos metido otros de un mismo marketplace, este proceso no serviría ya que encuentra el producto en la tabla y lo da por bueno. Necesitamos asegurarnos de que el producto tenga una línea por canal activo, de modo que si no encuentra el producto devuelve false, pero si lo encuentra tenemos que asegurarnos de que está para todos los canales activos del marketplace y si no enviar a insertarlo.
    public function checkProductoTabla() {
        $sql_select = "SELECT *
        FROM lafrips_mirakl_ofertas 
        WHERE sku_mirakl = '".$this->sku_mirakl."' 
        AND marketplace = '".$this->marketplace."'";

        $mirakl_ofertas = Db::getInstance()->executeS($sql_select); 

        if (count($mirakl_ofertas) > 0) {        
            //tenemos  que aseguarnos de que están todos los canales de cada marketplace. Si no encontramos alguno, tenemos los datos del producto para insertar en $mirakl_ofertas[0]. Para ello metemos cada código de canal en la tabla ofertas para el marketplace en un array, luego sacamos los códigos de canal de canales activos del array $marketplace_channels que los contiene y comparamos ambos con array_diff, que nos devuelve el/los códigos del array de channels que no se encuentren en el de ofertas
            $channels_mirakl_ofertas = array();
            foreach ($mirakl_ofertas AS $oferta) {                
                $channels_mirakl_ofertas[] = $oferta['channel'];
            }   

            $sql_channels = "SELECT channel_code FROM lafrips_mirakl_channels WHERE active = 1 AND marketplace = '".$this->marketplace."'";

            $marketplace_channels = Db::getInstance()->ExecuteS($sql_channels);  

            $marketplace_channels_array = array();
            foreach ($marketplace_channels AS $channel) {                
                $marketplace_channels_array[] = $channel['channel_code'];
            }

            //sacamos los elementos del primer array que no estén en el segundo
            $missing_channels = array_diff($marketplace_channels_array, $channels_mirakl_ofertas);

            if (!empty($missing_channels)) {
                //falta uno o más canales, hay que hacer un insert por cada uno con los datos del producto, que tenemos en $mirakl_ofertas[0] ya que al menos había uno
                foreach ($missing_channels AS $channel) {
                    $sql_insert = "INSERT INTO lafrips_mirakl_ofertas 
                    (marketplace, channel, id_product, id_product_attribute, sku_prestashop, sku_mirakl, nombre, date_add) 
                    VALUES
                    ('".$this->marketplace."', '".$channel."', ".$mirakl_ofertas[0]['id_product'].", ".$mirakl_ofertas[0]['id_product_attribute'].", '".$mirakl_ofertas[0]['sku_prestashop']."', '".$this->sku_mirakl."', '".pSQL($mirakl_ofertas[0]['nombre'])."', NOW())";
        
                    if (!Db::getInstance()->execute($sql_insert)) {
                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error insertando para marketplace '.$this->marketplace.', canal '.$channel.' producto referencia '.$mirakl_ofertas[0]['sku_prestashop'].' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas por ausencia de Canal'.PHP_EOL, FILE_APPEND); 
                
                        $this->error = 1;
                    
                        $this->mensajes[] = ' - Error insertando para marketplace '.$this->marketplace.', canal '.$channel.' producto referencia '.$mirakl_ofertas[0]['sku_prestashop'].' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas por ausencia de Canal';                
                    }
        
                    continue;
                } 

            }

            $this->id_product = $mirakl_ofertas[0]['id_product'];
            $this->id_product_attribute = $mirakl_ofertas[0]['id_product_attribute'];

            //el producto está en la tabla para el marketplace, lo marcamos activo, al no indicar canal se ponen todos
            $this->updateActive();     

            return true;
        } else {
            return false;
        }
    }   
    

    //función para hacer update active = 1 a tabla lafrips_mirakl_ofertas. Al no indicar canal lo pone a todos los del marketplace indicado
    public function updateActive() {   
        $sql_update = "UPDATE lafrips_mirakl_ofertas
        SET
        active = 1,					
        last_date_active = NOW(),
        date_upd = NOW()
        WHERE sku_mirakl = '".$this->sku_mirakl."' 
        AND marketplace = '".$this->marketplace."'";
        
        if (!Db::getInstance()->execute($sql_update)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error haciendo update active = 1 en marketplace '.$this->marketplace.' para producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error haciendo update active = 1 en marketplace '.$this->marketplace.' para producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas';
        }

        return;
    }

    
    //Llamamos a API OF52 /api/offers/export/async para solictar JSON de productos activos de un marketplace. No pedimos por canal
    //La api devuelve un tracking_id que guardamos en array $marketplace_configuration
    public function apiOFF52ProductosActivos() {
        //nos aseguramos de resetear esta variable para cada petición
        $this->tracking_id = false;

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/offers/export/async',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{  
                "export_type": "application/json",
                "include_inactive_offers": false,  
                "include_fields": [    
                  "product_sku",        
                  "shop_sku"
                ],    
                "items_per_chunk": 10000,
                "megabytes_per_chunk": 100
              }
              ',
            CURLOPT_HTTPHEADER => array(
                'Authorization: '.$this->shop_key,
                'Accept: application/json',
                'Content-Type: application/json'
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

            $error_message = 'Error API Mirakl para petición /api/offers/export/async de productos activos para marketplace '.ucfirst($this->marketplace).' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        if ($response) { 
            
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

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API a petición /api/offers/export/async para marketplace '.ucfirst($this->marketplace).' no es correcta'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API a petición /api/offers/export/async para marketplace '.ucfirst($this->marketplace).' no es correcta'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Message: '.$mensaje_error;                

                return false;
            }

            //si la llamada es correcta devuelve un código de importación
            $this->tracking_id = $response_decode->tracking_id;

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API a petición /api/offers/export/async para marketplace '.ucfirst($this->marketplace).' correcta'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tracking recibido - tracking_id = '.$this->tracking_id.PHP_EOL, FILE_APPEND);

            return true;            

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API a petición /api/offers/export/async para marketplace '.ucfirst($this->marketplace); 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }
    }

    //pedimos el status de la petición de productos activos que acabamos de hacer, con el tracking_id recibido. Si es correcto, el status será COMPLETED y devolverá un array de urls. Si no nos hemos pasado de tamaño o número de productos/items, fijado en la petición del informe, ese array solo contendrá una url
    public function apiOFF53StatusProductosActivos() {
        //nos aseguramos de resetear la variable cada vez
        $this->url_json = false;

        // echo '<br>url: '.$this->end_point.'/api/offers/export/async/status/'.$this->tracking_id;

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/offers/export/async/status/'.$this->tracking_id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',            
            CURLOPT_HTTPHEADER => array(
                'Authorization: '.$this->shop_key,
                'Accept: application/json'                
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

            $error_message = 'Error API Mirakl para petición /api/offers/export/async/status/:tracking_id de estado de petición de productos activos para marketplace '.ucfirst($this->marketplace).' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        if ($response) { 
            
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

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API a petición /api/offers/export/async/status/:tracking_id de estado de petición de productos activos para marketplace '.ucfirst($this->marketplace).' no es correcta'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API a petición /api/offers/export/async/status/:tracking_id de estado de petición de productos activos para marketplace '.ucfirst($this->marketplace).' no es correcta'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Message: '.$mensaje_error;                

                return false;
            }

            //si la llamada es correcta comprobamos el parámetro status que debe llegar en el json. FAILED es ERROR, COMPLETED es correcto
            if ($response_decode->status !== 'COMPLETED') {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API a petición /api/offers/export/async/status/:tracking_id de estado de petición de productos activos para marketplace '.ucfirst($this->marketplace).' tiene status '.$response_decode->status.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Error Code: '.$response_decode->error->code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Error Detail: '.$response_decode->error->detail.PHP_EOL, FILE_APPEND); 

                $this->error = 1;

                $this->mensajes[] = 'Atención, la respuesta de la API a petición /api/offers/export/async/status/:tracking_id de estado de petición de productos activos para marketplace '.ucfirst($this->marketplace).' tiene status '.$response_decode->status; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Error Code: '.$response_decode->error->code;  
                $this->mensajes[] = 'API Error Detail: '.$response_decode->error->detail;               

                return false;

            } else {
                //podría devolver más de una url si hubiera muchos datos, pero por ahora no parece que se de el caso. Habría que modificar items_per_chunk o megabytes_per_chunk en la petición original, según sea exceso de productos o de tamaño de archivo, o bien hacer un proceso que gestione varios archivos/urls   
                //27/06/2024 También puede darse el caso de que no devuelva ninguna url, podría ser un error o simplemente un marketplace sin abrir o quizás cerrado por vacaciones¿?. Recogemos el evento para no enviar a la sigueinte API una url inexistente, lo que da luegar a Exception              

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API a petición /api/offers/export/async/status/:tracking_id de estado de petición de productos activos para marketplace '.ucfirst($this->marketplace).' correcta'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);
                
                if (count($response_decode->urls) > 1) {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - La respuesta contiene más de una url por exceso de tamaño o productos'.PHP_EOL, FILE_APPEND);

                    return false;
                } elseif (count($response_decode->urls) < 1) {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - WARNING, No se recibió url para las ofertas. Indica ausencia de ofertas activas o marketplace cerrado o inactivo'.PHP_EOL, FILE_APPEND);

                    return false;
                } 

                //nos quedamos con la url única
                $this->url_json = $response_decode->urls[0];
                
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Url recibido - url_json = '.$this->url_json.PHP_EOL, FILE_APPEND);               
                
                return true; 
            }                       

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API a petición /api/offers/export/async/status/:tracking_id de estado de petición de productos activos para marketplace '.ucfirst($this->marketplace); 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }

    }

    public function apiOFF54GeTProductosActivos() {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->url_json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',            
            CURLOPT_HTTPHEADER => array(
                'Authorization: '.$this->shop_key,
                'Accept: application/json'                
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

            $error_message = 'Error API Mirakl para petición de JSON de productos activos para marketplace '.ucfirst($this->marketplace).' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        if ($response) { 
            
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

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API a petición de JSON de productos activos para marketplace '.ucfirst($this->marketplace).' no es correcta'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API a petición de JSON de productos activos para marketplace '.ucfirst($this->marketplace).' no es correcta'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Message: '.$mensaje_error;                

                return false;
            }

            //si la llamada es correcta , response sería el json sin más
            $this->productos_activos = $response_decode;

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API a petición de JSON de productos activos para marketplace '.ucfirst($this->marketplace).' correcta'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);  
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Productos activos = '.count($this->productos_activos).PHP_EOL, FILE_APPEND);                          

            return true;                                   

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API a petición /api/offers/export/async/status/:tracking_id de estado de petición de productos activos para marketplace '.ucfirst($this->marketplace); 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }
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
        $this->log_file = $this->log_path.'proceso_ofertas_activas_marketplaces_mirakl_'.date('Y-m-d H:i:s').'.txt';
                   
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso para descarga de productos activos para marketplaces Mirakl'.PHP_EOL, FILE_APPEND);       

        $sql_marketplaces_names = "SELECT marketplace FROM lafrips_mirakl_marketplaces WHERE active = 1";

        $marketplaces_names = Db::getInstance()->ExecuteS($sql_marketplaces_names);   
        $names = "";
        foreach ($marketplaces_names AS $marketplace_name) {
            $names .= ucfirst($marketplace_name['marketplace']).", ";
        }        
        //quitamos la última coma
        $names = rtrim($names, ', ');

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.count($marketplaces_names).' marketplaces a procesar: '.$names.PHP_EOL, FILE_APPEND);           

        return;        
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

        $asunto = 'ERROR descargando ofertas activas de marketplaces Mirakl '.date("Y-m-d H:i:s");
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





    //FUNCIONES DEL PROCESO ANTES DE DEJAR DE COMPROBAR ERRORES Y BUSCAR LA INFO DEL PRODUCTO, OJO PORQUE ALGUNA TABLA HA SIDO MODIFICADA
    //función que compara objeto $this->productos_activos con array $this->productos_tabla_mirakl_ofertas para saber, dado un marketplace y un canal, qué productos activos están en la tabla, asegurando que estén marcados active o insertándolos, y cuales ya no aparecen activos en el marketplace, asegurando que estén marcados no active en la tabla
    // public function procesaProductosOLD() {
    //     $this->sku_prestashop = null; 
    //     $this->sku_mirakl = null; 
     
    //     $contador = 0;
    //     //recorremos los productos de $this->productos_activos sacando sus sku
    //     foreach ($this->productos_activos AS $producto_activo) {

    //         if ($this->test && $contador > 20) {
    //             break;
    //         }

    //         $this->sku_prestashop = $producto_activo->shop_sku;
    //         $this->sku_mirakl = $producto_activo->product_sku;

    //         //llamamos a checkProducto() para comprobar si el producto se encuentra en la tabla para este marketplace y canal, buscando en array $this->productos_tabla_mirakl_ofertas. Buscamos la sku_mirakl
    //         if ($this->checkProducto() == false) {
    //             //el producto no está en la tabla, lo insertamos
    //             $this->insertTabla();
    //         }

    //         $contador++;

    //         continue;
    //     }

    //     //ahora comprobamos los productos que todavía estén en array de tabla $this->productos_tabla_mirakl_ofertas y los marcamos todos como active = 0
    //     $this->procesaRestoTabla();
        
    //     return true;
    // }

    // //función que recoge las referencias de los productos que están en la tabla lafrips_mirakl_ofertas para un marketplace y canal
    // public function productosMiraklOfertasOLD() {
    //     $sql_productos_tabla = "SELECT id_mirakl_ofertas, id_product, id_product_attribute, sku_prestashop, sku_mirakl, active
    //     FROM lafrips_mirakl_ofertas
    //     WHERE marketplace = '".$this->marketplace."'
    //     AND channel = '".$this->channel."'";

    //     $this->productos_tabla_mirakl_ofertas = Db::getInstance()->ExecuteS($sql_productos_tabla);      

    //     //para cuando aún no hay productos en la tabla ponemos una variable test, si su valor es true no tendrá en cuenta que no hay productos para la búsqueda
    //     if ($this->test == false && (!$this->productos_tabla_mirakl_ofertas || !is_array($this->productos_tabla_mirakl_ofertas) || count($this->productos_tabla_mirakl_ofertas) < 1)) {
    //         $this->error = 1;
    //         $this->mensajes[] = "Error, No se pudieron obtener los productos de lafrips_mirakl_ofertas para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel;            

    //         file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Error, No se pudieron obtener los productos de lafrips_mirakl_ofertas para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel.PHP_EOL, FILE_APPEND);     
            
    //         return false;
    //     } 
                      
    //     $this->mensajes[] = "Productos obtenidos de lafrips_mirakl_ofertas para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel." = ".count($this->productos_tabla_mirakl_ofertas);  
            
    //     file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Productos obtenidos de lafrips_mirakl_ofertas para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel." = ".count($this->productos_tabla_mirakl_ofertas).PHP_EOL, FILE_APPEND); 
                
    //     return true;
    // }

    // //función que recorre el array de productos que había en tabla y los marca con active = 0 dado que no han venido en productos activos
    // public function procesaRestoTablaOLD() {
    //     $contador = 0;

    //     foreach ($this->productos_tabla_mirakl_ofertas AS $producto_tabla_mirakl_ofertas) {
    //         if ($producto_tabla_mirakl_ofertas['active']) {
    //             $this->updateActive($producto_tabla_mirakl_ofertas['id_mirakl_ofertas'], 0);

    //             $contador++;
    //         }            
    //     }

    //     file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Marcando active = 0 en tabla lafrips_mirakl_ofertas a '.$contador.' productos que ya no están activos en marketplace '.ucfirst($this->marketplace).' canal '.$this->channel.PHP_EOL, FILE_APPEND); 

    //     return;
    // }

    // //función para hacer update a tabla lafrips_mirakl_ofertas, puede ser cambiar active a 1 o 0
    // public function updateActiveOLD($id_mirakl_ofertas, $active) {   
    //     if ($active) {
    //         $sql_active = " last_date_active = NOW(), ";
    //     } else {
    //         $sql_active = " last_date_deactivated = NOW(), ";
    //     }     

    //     $sql_update = "UPDATE lafrips_mirakl_ofertas
    //     SET 
    //     active = $active,
    //     $sql_active  
    //     date_upd = NOW()
    //     WHERE id_mirakl_ofertas = ".$id_mirakl_ofertas;

    //     if (!Db::getInstance()->execute($sql_update)) {
    //         file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error marcando active = '.$active.' en tabla lafrips_mirakl_ofertas para id_mirakl_ofertas = '.$id_mirakl_ofertas.PHP_EOL, FILE_APPEND); 
    
    //         $this->error = 1;
        
    //         $this->mensajes[] = ' - Error marcando active = '.$active.' en tabla lafrips_mirakl_ofertas para id_mirakl_ofertas = '.$id_mirakl_ofertas;
    //     }

    //     return;
    // }

    // //función que con la sku_prestashop devuelve los datos de un producto, id_produt, id_product_attribute y nombre (con atributos si lo tiene)
    // public function getInfoProductoOLD() {        

    //     $sql_info_producto = "SELECT ava.id_product, ava.id_product_attribute, 
    //     IFNULL(CONCAT(pla.name, ' : ', GROUP_CONCAT(DISTINCT agl.name, ' - ', atl.name ORDER BY agl.name SEPARATOR ', ')), pla.name) AS nombre
    //     FROM lafrips_stock_available ava
    //     JOIN lafrips_product pro 
    //         ON pro.id_product = ava.id_product
    //     JOIN lafrips_product_lang pla 
    //         ON pla.id_product = pro.id_product AND pla.id_lang = 1
    //     LEFT JOIN lafrips_product_attribute pat 
    //         ON pat.id_product = pro.id_product AND ava.id_product_attribute = pat.id_product_attribute
    //     LEFT JOIN lafrips_product_attribute_combination pac 
    //         ON pac.id_product_attribute = pat.id_product_attribute
    //     LEFT JOIN lafrips_attribute att 
    //         ON att.id_attribute = pac.id_attribute
    //     LEFT JOIN lafrips_attribute_lang atl 
    //         ON atl.id_attribute = pac.id_attribute AND atl.id_lang = 1
    //     LEFT JOIN lafrips_attribute_group_lang agl 
    //         ON agl.id_attribute_group = att.id_attribute_group AND agl.id_lang = 1
    //     WHERE (pro.reference = '".$this->sku_prestashop."' OR pat.reference = '".$this->sku_prestashop."')
    //     GROUP BY ava.id_product, ava.id_product_attribute";
        
    //     $info_producto = Db::getInstance()->executeS($sql_info_producto);

    //     //si devuelve más de un resultado puede ser error porque haya varios productos con la misma referencia o puede ser que tenemos la referencia base de un producto con atributos, en cuyo caso nos devuelve los datos de todos los atributos, en ambos casos es error y hay que señalarlo
    //     if (count($info_producto) > 1) {
    //         file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, sku_prestashop '.$this->sku_prestashop.' corresponde a '.count($info_producto).' productos'.PHP_EOL, FILE_APPEND);
    //         file_put_contents($this->log_file, date('Y-m-d H:i:s').' - La referencia puede estar reptida en varios productos o corresponde a la referencia base de un producto con atributos'.PHP_EOL, FILE_APPEND); 
    //         file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
    //         $this->error = 1;
        
    //         $this->mensajes[] = ' - Error, sku_prestashop '.$this->sku_prestashop.' corresponde a '.count($info_producto).' productos';
    //         $this->mensajes[] = ' - La referencia puede estar reptida en varios productos o corresponde a la referencia base de un producto con atributos';
    //         $this->mensajes[] = ' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas';

    //         return false;
    //     } elseif (count($info_producto) < 1) {
    //         file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, sku_prestashop '.$this->sku_prestashop.' no se encuentra en base de datos de Prestashop'.PHP_EOL, FILE_APPEND);            
    //         file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
    //         $this->error = 1;
        
    //         $this->mensajes[] = ' - Error, sku_prestashop '.$this->sku_prestashop.' no se encuentra en base de datos de Prestashop';            
    //         $this->mensajes[] = ' - Se omite la inserción de sku_prestashop '.$this->sku_prestashop.' en tabla lafrips_mirakl_ofertas';

    //         return false;
    //     } else {
    //         //devolvemos los datos accesibles tal que $info_producto['id_product'] y no $info_producto[0]['id_product']
    //         return $info_producto[0];
    //     }
    // }

    // //función para insertar en tabla lafrips_mirakl_ofertas un producto que viene como activo en el marketplace pero no está en la tabla
    // public function insertTablaOld() {
    //     //primero tenemos que obtener los datos del producto a insertar, id_product, id_product_attribute y nombre
    //     // if (!$info_producto = $this->getInfoProducto()) {
    //     //     return;
    //     // }

    //     $sql_insert = "INSERT INTO lafrips_mirakl_ofertas 
    //     (marketplace, channel, sku_prestashop, sku_mirakl, active, last_date_active, date_add) 
    //     VALUES 
    //     ('".$this->marketplace."', '".$this->channel."', '".$this->sku_prestashop."', '".$this->sku_mirakl."', 1, NOW(), NOW())";
       			
    //     if (!Db::getInstance()->execute($sql_insert)) {
    //         file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error insertando producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
    //         $this->error = 1;
        
    //         $this->mensajes[] = ' - Error insertando producto referencia '.$this->sku_prestashop.' sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas';
    //     }

    //     return;
    // }

    // //queremos iterar los canales si hay más de uno (ES y PT en Worten, p.ejemplo) para insertar o actualizar los productos en la tabla.Consideramos que todos los canales de un marketplace tienen los mismos productos activos y para la tabla, puesto que trataremos de sacar la buybox por canal, metemos cada producto por cada marketplace-canal, es decir, una línea por producto-marketplace-canal
    // // foreach ($value['channels'] AS $canal => $info_canal) {
    // //     $this->channel = $info_canal['channel_code'];
    // //     $this->channel_active = $info_canal['activo'];

    // //     file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Solicitando productos activos Canal '.$canal.' - '.$this->channel.' (Activo '.($this->channel_active == 1 ? 'SI' : 'NO').')'.PHP_EOL, FILE_APPEND);                 

    // //     //sacamos de lafrips_mirakl_ofertas los productos que haya para el marketplace y canal. Cada producto que coincida comprobaremos que esté activo en la tabla y lo eliminaremos del array, los que queden en el array serán marcados como no activos. Se almacenan en array $this->productos_tabla_mirakl_ofertas
    // //     if (!$this->productosMiraklOfertas()) {
    // //         //error obteniendo productos de tabla, pasamos a siguiente canal                
    // //         continue;
    // //     } 

    // //     //procesamos el JSON de productos activos que ha sido pasado a objeto, $this->productos_activos, comparando además con los que ya estaban en tabla en $this->productos_tabla_mirakl_ofertas
    // //     if (!$this->procesaProductos()) {
    // //         //error procesando productos para tabla, pasamos a siguiente canal                
    // //         continue;
    // //     } 

    // // }

    // //función que busca un producto en el array $this->productos_tabla_mirakl_ofertas por su sku_mirakl sacado del json objeto que tenemos en $this->productos_activos. Si lo encuentra se asegura de que esté como active = 1 en la tabla lafrips_mirakl_ofertas y lo saca del array, devolviendo true, si no lo encuentra devuelve false
    // public function checkProductoOLD() {
    //     if ($indice = array_search($this->sku_mirakl, array_column($this->productos_tabla_mirakl_ofertas, 'sku_mirakl'))) { 
    //         //producto encontrado, sacamos su sku_prestashop para comparar con la que tenemos en el marketplace y revisar errores. Tenemos el indice del array correspondiente al producto en $indice
    //         if ($this->productos_tabla_mirakl_ofertas[$indice]['sku_prestashop'] != $this->sku_prestashop) {
    //             //las referencias no coinciden, guardamos error 
    //             $error_message = "sku_prestashop en tabla no coincide con shop_sku de marketplace (".$this->sku_prestashop.")";

    //             $this->setError($this->productos_tabla_mirakl_ofertas[$indice]['id_mirakl_ofertas'], $error_message);

    //             file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error, sku_prestashop en tabla no coincide con shop_sku de marketplace'.ucfirst($this->marketplace).PHP_EOL, FILE_APPEND);
    //             file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Producto '.$this->productos_tabla_mirakl_ofertas[$indice]['id_product'].'_'.$this->productos_tabla_mirakl_ofertas[$indice]['id_product_attribute'].PHP_EOL, FILE_APPEND); 
    //             file_put_contents($this->log_file, date('Y-m-d H:i:s').' - sku_prestashop = '.$this->productos_tabla_mirakl_ofertas[$indice]['sku_prestashop'].' != '.$this->sku_prestashop.PHP_EOL, FILE_APPEND); 

    //             $this->error = 1;
                
    //             $this->mensajes[] = 'Error, sku_prestashop en tabla no coincide con shop_sku de marketplace '.ucfirst($this->marketplace); 
    //             $this->mensajes[] = 'Producto '.$this->productos_tabla_mirakl_ofertas[$indice]['id_product'].'_'.$this->productos_tabla_mirakl_ofertas[$indice]['id_product_attribute'];
    //             $this->mensajes[] = 'sku_prestashop = '.$this->productos_tabla_mirakl_ofertas[$indice]['sku_prestashop'].' != '.$this->sku_prestashop;     

    //         }

    //         //comprobamos que active en tabla es 1 dado que si tenemos el producto es que está activo en marketplace
    //         if (!$this->productos_tabla_mirakl_ofertas[$indice]['active']) {
    //             $this->updateActive($this->productos_tabla_mirakl_ofertas[$indice]['id_mirakl_ofertas'], 1);
    //         }

    //         //en lugar de unset() que da serios problemas después con el índice del array al no reindexarse utilizamos array_splice que "saca" el elemento del índice que pasamos y después el array queda con los índices actualizados
    //         // array_splice($array, indice_elemento_a_eliminar, numero_de_elementos_a_eliminar);
    //         array_splice($this->productos_tabla_mirakl_ofertas, $indice, 1);
    //         // unset($this->productos_prestashop[$indice]);

    //         return true; 
    //     }

    //     return false;
    // } 
    
    // //función para marcar error en un producto
    // public function setErrorOLD($id_mirakl_ofertas, $error_message) {
    //     $error_message = pSQL($error_message);

    //     $sql_update = "UPDATE lafrips_mirakl_ofertas
    //     SET 
    //     error = 1,
    //     date_error = NOW(), 
    //     error_message = CONCAT(error_message, ' | $error_message - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),    
    //     date_upd = NOW()
    //     WHERE id_mirakl_ofertas = ".$id_mirakl_ofertas;

    //     if (!Db::getInstance()->execute($sql_update)) {
    //         file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error marcando Error en tabla lafrips_mirakl_ofertas. Mensaje error = '.$error_message.' - id_mirakl_ofertas = '.$id_mirakl_ofertas.PHP_EOL, FILE_APPEND); 
    
    //         $this->error = 1;
        
    //         $this->mensajes[] = ' - Error marcando Error en tabla lafrips_mirakl_ofertas. Mensaje error = '.$error_message.' - id_mirakl_ofertas = '.$id_mirakl_ofertas;
    //     }

    //     return;
    // }


}

unset($a);


