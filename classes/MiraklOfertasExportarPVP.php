<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');

//https://lafrikileria.com/tareas_programadas/mirakl/proceso/MiraklOfertasPVP.php
//https://lafrikileria.com/test/tareas_programadas/mirakl/proceso/MiraklOfertasPVP.php


//https://lafrikileria.com/modules/miraklfrik/classes/MiraklOfertasExportarPVP.php?cron=true

//30/05/2024 Proceso para gestionar los pvp para ganar la buybox en cada marketplace. Se sacarán los productos activos en cada canal de cada marketplace de la tabla lafrips_mirakl_ofertas y se solicta por API P11 la lista de ofertas para cada producto, tratando de obtener el pvp más bajo de otro vendedor y si es posible se calculará un pvp menor para nosotros, almacenándolo, de modo que cuando se actualiza el catálogo se envíe dicho pvp en lugar del que tenemos en Prestashop.
//la API P11 solo permite acceder a 100 ofertas cada vez de modo que está por ver si esto es viable ya que entre todos los  marketplaces necesitamos cientos de llamadas a la API

//25/06/2024 Metemos la clase en módulo miraklfrik

// ini_set('error_log', _PS_ROOT_DIR_.'/modules/miraklfrik/log/error/php_error.log');

// // Turn on error reporting
// ini_set('display_errors', 1);
// // error_reporting(E_ALL); cambiamos para que no saque E_NOTICE
// error_reporting(E_ERROR | E_WARNING | E_PARSE | E_DEPRECATED | E_STRICT);

if (isset($_GET['cron']) && $_GET['cron'] == 'true') {
    $a = new MiraklOfertasExportarPVP();
} else {
    exit;
}

class MiraklOfertasExportarPVP
{
    //variable donde guardaremos los productos que están en tabla lafrips_mirakl_ofertas para un marketplace y canal 
    public $productos_tabla_mirakl_ofertas = array();    

    //variable donde iremos metiendo la cadena de skus de mirakl separadas por coma para solictar ofertas en bloques de 100
    public $skus;

    //aquí guardaremos el resultado de la solictud del JSON con las ofertas de una lista de productos de un marketplace y canal
    public $ofertas_productos; 
    
    //aquí guardaremos las ofertas de un producto de un marketplace y canal
    public $ofertas_producto; 

    //variable para el json de ofertas de productos que subiremos a mirakl con la API OF24
    public $json_ofertas_productos;

    //variable para el array de oferta individual para cada sku, que será parte del $json_ofertas_productos una vez codificado a json
    public $array_oferta_producto;

    //variable para el array de ofertas de productos que será el $json_ofertas_productos una vez codificado a json
    public $array_ofertas_productos;    

    //para no asignar la variable cientos de veces, ponemos aquí ya las fechas para la subida de productos
    //preparamos available-start-date y available-end-date. Parece ser la fecha de ejecución en formato YYYY-MM-DD y esa fecha más 10 días. Vamos a poner como fecha inicio hoy menos uno por un posible error de fechas en el cambio de día
    public $hoy_menos_1; 
    public $hoy_mas_10;
    
    public $sku_prestashop; 

    public $sku_mirakl; 

    //variable que contendrá los id_supplier de proveedores que entran en el proceso para productos sin stock. Almacenados en 'PROVEEDORES_VENTA_SIN_STOCK' en lafrips_caonfiguration
    // Cerdá - 65, Karactermanía - 53, 24 - Redstring, 8 - Erik, 121 - Distrineo, 111 - Noble 
    public $proveedores_sin_stock; 

    //04/12/2024 Categoría para que puedan meter a mano productos que no queremos exportar a Mirakl, en principio para todos los  marketplaces, si no habría que crear diferentes categorías. Lo que haremos será, a los que tengan esa categoría, forzarles siempre stock 0 de modo que si un producto ya está en el marketplace no se quede colgado. 
    public $categoria_no_mirakl = 2824;

    public $contador_productos = 0;

    public $mensajes = array();

    public $error = 0;      

    public $log = true;    

    public $test = true;

    //variable para el archivo a generar en el servidor con las líneas log
    public $log_file;   

    //carpeta de archivos log    
    public $log_path = _PS_ROOT_DIR_.'/modules/miraklfrik/log/exportar_pvp/';   
    
    //almacenamos max_execution_time para saber cuando parar si tarda demasiado. El valor que almacenamos es el 90% de la variable php, de modo que si lo superamos no continuaremos con más productos
    public $my_max_execution_time;
    //momento de inicio del script, en segundos, para comparar con max_execution_time
    public $inicio;
    //un segundo max execution time definido a x segundos, para programar z veces por hora el cron en producción, asegurándome de que no llegará a max_execution_time de PHP. Programaré la tarea dos veces por hora, a y 5 y a y 35. Así al tener un max de 25 minutos parará cuando alcnace el primer límite, que podría ser 25 o si alguien modifica max_execution_time de PHP y lo acorta o alarga. TENEMOS EN PHP DEFINIDO 50 minutos. Ejecuto una vez por hora

    //un segundo max execution time definido a x minutos para programarlo dos/tres veces por hora sin que se solapen. Pongo 25 minutos para dos veces (1500 sec) o 18 minutos para 3 veces ()
    //18 minutos 1080 segundos
    //8 minutos (480 sec) para ejecutarlo cada 10
    public $max_execution_time_x_minutos = 1080;

    public $pvp_minimo;
    public $pvp_publicacion;
    public $pvp_exportado;
    public $pvp_buybox;
    public $friki_buybox;
    public $total_vendedores;
    public $otros_vendedores_pvp_min;
    public $otros_vendedores_min_pvp_nombre;

    //para almacenar las credenciales para la conexión a la API según el Marketplace. Tendrá formato array($end_point, $api_key)
    //por ahora también meteré algunas variables por marketplace, por ejemplo, si exportar productos con venta sin stock o no, etc
    public $marketplaces_credentials = array();    

    //variables donde se almacena el marketplace que estamos procesando, su out_of_stock, su modificacion_pvp
    public $marketplace;
    public $channel;
    public $channel_active;    
    public $channel_principal;
    public $end_point;
    public $shop_key; 
    public $import_id;   

    //array con los posibles parámetros necesarios por cada marketplace, por ejemplo, out_of_stock 1 o 0 indicando si enviamos los de permitir pedido con stock o no, campos específicos que deben ir en el csv a exportar solo para ese marketplace, etc. En este proceso no se utiliza todo
    //añado los canales de cada marketplace
    //31/05/2024 Pasamos a utilizar las tablas lafrips_mirakl_marketplaces y lafrips_mirakl_channels para la info de marketplaces etc
    //31/05/2024 variable para guardar la info de marketplaces que sacaremos de lafrips_mirakl_marketplaces en lugar de utilizar el array $marketplace_configuration
    public $marketplaces;
    public $marketplaces_channels;    
          

    public function __construct() {    

        date_default_timezone_set("Europe/Madrid");

        $this->inicio = time();
        $this->my_max_execution_time = ini_get('max_execution_time')*0.9; //90% de max_execution_time   

        //preparamos log        
        $this->setLog();   

        if (!$this->getCredentials()) {
            $this->enviaEmail();

            exit;
        }             

        //ponemos check_pvp a 0 para toda la tabla lafrips_mirakl_ofertas        
        if (!$this->resetCheckPVP()) {
            $this->enviaEmail();

            exit;
        }

        //antes de procesar los productos sacamos los id_supplier de proveedores sin stock permitidos para marketplaces, almacenados en lafrips_configuration 
        $this->proveedores_sin_stock = explode(",", Configuration::get('PROVEEDORES_VENTA_SIN_STOCK'));
        
        //tenemos que procesar cada marketplace y cada canal
        $this->procesaProductosMarketplaces();              

        $this->mensajes[] = "Proceso de análisis de ofertas de productos activos de marketplaces terminado";    
        $this->mensajes[] = "Total productos analizados: ".$this->contador_productos;      
            
        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Proceso de análisis de ofertas de productos activos de marketplaces terminado".PHP_EOL, FILE_APPEND);  
        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Total productos analizados: ".$this->contador_productos.PHP_EOL, FILE_APPEND);  

        echo "Proceso de análisis de ofertas de productos activos de marketplaces terminado. Productos analizados: ".$this->contador_productos;

        if ($this->error) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - PROCESO FINALIZADO CON ERRORES".PHP_EOL, FILE_APPEND);   

            $this->enviaEmail();
        }
        
    } 
    
    //función que hace la gestión para ir recorriendo marketplaces y canales, para ello obtenemos los canales activos de lafrips_mirakl_channels y los vamos recorriendo 
    public function procesaProductosMarketplaces() {
        //los canales tenemos que obtenerlos por orden dentro del marketplace, primero el principal, para que se procesen sus pvp primero, dado que ese será el "price" base que se envía al marketplace para todos los canales. De este modo, caundo proesemos los productos de un canal no principal, el pvp del principal ya estará disponible
        $sql_channels = "SELECT * FROM lafrips_mirakl_channels WHERE active = 1 ORDER BY marketplace_id, principal DESC";

        $this->marketplaces_channels = Db::getInstance()->ExecuteS($sql_channels);      

        if (!$this->marketplaces_channels || !is_array($this->marketplaces_channels) || count($this->marketplaces_channels) < 1) {
            $this->error = 1;
            $this->mensajes[] = "No se pudo obtener la información de los canales activos desde la Base de Datos";            

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - No se pudo obtener la información de los canales activos desde la Base de Datos'.PHP_EOL, FILE_APPEND);     
            
            return;
        } 

        //como available_started vamos a enviar hoy menos 1 porque sospecho que cuando se ejecuta a primera hora de la madrugada puede haber una diferencia de horarios entre servidores y quizás estoy enviando hoy, mientras en el receptor aún es ayer y se desactivan productos durante esa hora de diferencia hasta cambio de día
        // $this->hoy = date("Y-m-d"); 
        $this->hoy_menos_1 = date("Y-m-d", strtotime("-1 days", strtotime(date("Y-m-d"))));
        $this->hoy_mas_10 = date("Y-m-d", strtotime("+10 days", strtotime(date("Y-m-d"))));

        //queremos iterar los canales si hay más de uno (ES y PT en Worten, p.ejemplo) para sacar los productos en la tabla lafrips_mirakl_ofertas marcados como active = 1. Puesto que trataremos de sacar la buybox por canal, tenemos cada producto por cada marketplace-canal, es decir, una línea por producto-marketplace-canal
        foreach ($this->marketplaces_channels AS $channel) {                 
            //preparamos las variables necesarias 
            $this->marketplace = $channel['marketplace'];          

            $this->channel = $channel['channel_code'];

            $this->channel_principal = $channel['principal'];

            //url endponit y shop_key sacamos de credentials            
            $this->end_point = $this->marketplaces_credentials[$this->marketplace]['url'];

            $this->shop_key = $this->marketplaces_credentials[$this->marketplace]['shop_key'];                

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.', ISO '.$channel['iso'].' - Ofertas productos activos:'.PHP_EOL, FILE_APPEND);  

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Solicitando productos activos para '.$this->channel.PHP_EOL, FILE_APPEND);             
                
            //la API P11 solo permite solicitar ofertas de productos de 100 en 100 productos, así que hay que hacer un do while hasta que se hayan obtenido todos los de cada marketplace-canal
            $exit = 0;            

            //reseteamos el json y el array de productos para cada canal
            $this->array_ofertas_productos = array(); 
            $this->json_ofertas_productos = "";   

            do {        
                //obtenemos 100 productos de tabla, que se almacenarán en $this->skus
                $get_skus = $this->getSkus();

                if ($get_skus === true) {
                    //llamamos a la API P11 con los skus para solitar las ofertas del bloque de skus que se almacenarán en $this->ofertas_productos
                    if (!$this->apiP11OfertasProducto()) {
                        //error con proceso APIs, volvemos al loop               
                        continue;
                    } 
            
                    // echo '<pre>';
                    // print_r($this->ofertas_productos);
                    // echo '</pre>';
            
                    //hemos obtenido las ofertas, las enviamos a procesar                        
                    if (!$this->procesaOfertasProductos()) {
                        //error con procesos, volvemos al loop               
                        continue;
                    }    

                    // echo '<pre>';
                    // print_r($this->json_ofertas_productos);
                    // echo '</pre>';

                    // exit;
                    

                } elseif ($get_skus === false) {
                    //no hay productos en la tabla activos, con check_pvp = 0 para el marketplace y canal, interrumpimos el proceso, salimos del do - while para pasar enviar los productos que ya hemos procesado y pasar a otro canal
                    break;
                }
                
                //comprobamos el tiempo que llevamos cada vez, si se alcanza no solo salimos del do while sino que hay que terminar la ejecución, para ello debemos salir del do while, del foreach de canales y del foreach de marketplaces utilizando break con el label que le hemos puesto al foreach externo
                //NO ,  AL ACUMULAR TODOS LOS PRODUCTOS PARA HACER UNA SOLA LLAMADA A OF24, AQUÍ YA NO SALIMOS DEL TODO, SOLO DEL DO WHILE PARA ENVIAR LOS QUE SE HAYAN PROCESADO, ES DECIR break EN LUGAR DE break 2;
                if (((time() - $this->inicio) >= $this->my_max_execution_time) || ((time() - $this->inicio) >= $this->max_execution_time_x_minutos)) {                   
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tiempo ejecución alcanzando límite, interrumpimos DO WHILE'.PHP_EOL, FILE_APPEND);

                    break;
                }

            } while (!$exit);   

            //24/07/2024 Para evitar llamar a API OF24 una vez por cada 100 productos, vamos a ir acumulando los pedidos en un solo json que enviaremos de una sola vez. Llamaremos a API OF24 cuando hayamos terminado el canal

            //hemos recorrido todos los productos del canal, de 100 en 100, guardando cada array $array_oferta_producto dentro del array $array_ofertas_productos. Lo metemos en un último array para codificar a json que luego lanzaremos a API OF24
            if (!empty($this->array_ofertas_productos)) {
                $offers_array = array(
                    "offers" => $this->array_ofertas_productos
                );
        
                $this->json_ofertas_productos = json_encode($offers_array);  

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Productos procesados: '.count($this->array_ofertas_productos).PHP_EOL, FILE_APPEND);

            } else {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Productos NO procesados'.PHP_EOL, FILE_APPEND);

                return false;
            }
            
            //llamamos a la API OF24 con las ofertas que queremos realizar nosotros para cada producto sobre los skus que hemos obtenido. El json con todas las ofertas estará en $json_ofertas_productos
            if (!$this->apiOF24OfertasProducto()) {
                //error con proceso APIs, pasamos a siguiente canal              
                continue;
            } else {
                //comprobamos si hay informe de error con el import_id llamando a API OF03. Si devuelve 404 not found es que no hubo errores, consideraremos eso como OK
                if (!$this->apiOF03ErrorReport()) {
                    //error con proceso APIs, pasamos a siguiente canal            
                    continue;
                }
            }

        }

        return;
    }


    public function procesaOfertasProductos() {           
        
        //en $this->ofertas_productos tenemos un array con las ofertas de x productos (normalmente 100), lo recorremos enviando cada grupo de ofertas de un producto individual a ser procesado
        foreach ($this->ofertas_productos AS $this->ofertas_producto) {
            $this->contador_productos++;

            if ($this->procesaOfertasProducto()) {
                //con el pvp a exportar definido preparamos el json del producto individual
                if ($this->preparaOfertaJSON()) {
                    //ya hemos indicado el pvp a exportar para la oferta, tenemos que añadir el array $array_oferta_producto al array $array_ofertas_productos
                    $this->array_ofertas_productos[] = $this->array_oferta_producto;
                }
            }                
        }

        return true;
    }

    //función que obtiene el vendedor más barato para una oferta etc
    //Si tengo la buybox y no hay más vendedores, ponemos el pvp de publicación.
    //Si tengo buybox pero hay otros vendedores, busco el más barato. Si su pvp está por encima del pvp de publicación dejo pvp de publicación. Si está por debajo de pvp de publicación, ponemos ese pvp rebajado 5 centimos,siempre que quede por encima del pvp mínimo.
    //No tengo buybox, busco el más barato y resto 5 centimos. Si esta por debajo del pvp minimo dejamos el pvp mínimo. Si no queda por debajo dejamos el pvp del otro vendedor menos 5 centimos.
    //en resumen, poner pvp de publicación siempre que sea posible, o pvp de competidor más barato menos 5 centimos, sin bajar de pvp mínimo.
    //esta función: 
    //primero, saca la sku del producto en mirakl para poder localizar el producto en lafrips_mirakl_ofertas. 
    //Después saca total_count que es el total de vendedores de la oferta. Si este valor fuera 0 es que nadie la tiene a la venta, en nuestro caso podría ser que se nos ha agotado entre la última vez que actualizamos ofertas activas y la ejecución de este script. Sería raro pero puede suceder. Habría que pasar a otro producto. Si el valor es uno nos aseguramos de que somos nosotros sacando shop_name del vendedor. Si no fueramos nosotros pasamos a otro producto. Si somos nosotros nos aseguramos de que el pvp a exportar sea pvp de publicación, y limpiamos otros_min_pvp y otros_min_pvp_nombre.
    //en los casos en que por lo que sea, no hay vendedores, o hay uno y no somos nosotros, ponemos active = 0 al producto y pvp exportado como pvp de publicacion en lugar de marcar error en la tabla, pero metemos mensaje de error y lo enviamos por email
    //Si total_count es más de uno sacamos el más barato, quien tiene la buybox, que almacenaremos en buybox y date_buybox. Si somos nosotros ponemos friki_buybox a 1 y buscamos el segundo más barato, guardando su nombre y pvp. Si su pvp está por encima de nuestro pvp de publicación, aseguramos que nuestro pvp exportado sea pvp de publicación, si no, 5 centimos por debajo del pvp del otro vendedor.
    //Si nosotros no tenemos la buybox, ponemos como pvp exportado 5 centimos menos del pvp del vendedor de buybox, sin bajar por debajo de pvp minimo, dejando como mínimo pvp_minimo
    //sabemos que las ofertas devueltas por la API están ordenadas por defecto de más barato a más caro, ordenando por 'total_price', devolviendo solo las 10 primeras, de modo que sabemos que la más barata es la de la posición 0 del array de 'offers', así que trabajariamos con el contenido de $this->ofertas_producto['offers'][0]. Parece que para obtener el orden correcto hay que especificar el código de canal tanto en el parámetro channel_codes como en pricing_channel_codes
    //04/06/2024 En worten la api devuelve el subarray all_prices con cada canal, pero pccomponentes y mediamarkt no, de modo que no podemos hacerlo así. Parece que al añadir el parámetro pricing_channel_code si que podríamos fiarnos del total_price para el canal, así que no habría que entrar en subarrays. Tampoco haría falta sumar o no gastos de envío ya que van incluidos en total_price.
    //vamos a guardar en lafrips_mirakl_ofertas otros_min_pvp y otros_min_pvp_nombre, será el vendedor más barato que no seamos nosotros
    public function procesaOfertasProducto() {
        // $price = 0;        
        // $shipping = 0;       

        $this->sku_mirakl = $this->ofertas_producto['product_sku'];

        //total de vendedores activos del producto
        $this->total_vendedores = $this->ofertas_producto['total_count'];

        if ($this->total_vendedores == 0 || empty($this->ofertas_producto['offers'])) {            

            $this->otros_vendedores_min_pvp_nombre = '';

            $this->otros_vendedores_pvp_min = 0;

            $this->resetProductoTablaOferta('No se recibieron ofertas para el producto');            

            $this->error = 1;            

            $this->mensajes[] = "Error, No se recibieron ofertas para el producto sku_mirakl ".$this->sku_mirakl." para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel;            

            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Error, No se recibieron ofertas para el producto sku_mirakl ".$this->sku_mirakl." para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel.PHP_EOL, FILE_APPEND);      
            
            return false;
        }

        //hay vendedores, comprobamos cuantos para proceder
        if ($this->total_vendedores == 1) {
            //una sola oferta, nos aseguramos de ser nosotros. Hasta mejor método, revisamos que el nombre coincida, con y sin tilde en la i
            if (($this->ofertas_producto['offers'][0]['shop_name'] != 'La Frikilería') && ($this->ofertas_producto['offers'][0]['shop_name'] != 'La Frikileria')) {
                //no estamos como vendedores, marcamos el error y "reseteamos" el producto 
                //07/08/2024 metemos pSQL porque han aparecido nombres con comillas etc              
                $this->otros_vendedores_min_pvp_nombre = pSQL($this->ofertas_producto['offers'][0]['shop_name']);

                $this->otros_vendedores_pvp_min = $this->ofertas_producto['offers'][0]['total_price'];

                $this->resetProductoTablaOferta('LaFrikilería no aparece como vendedor activo para el producto');

                $this->error = 1;            

                $this->mensajes[] = "Error, LaFrikilería no aparece como vendedor activo para el producto sku_mirakl ".$this->sku_mirakl." para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel;            

                file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Error, LaFrikilería no aparece como vendedor activo para el producto sku_mirakl ".$this->sku_mirakl." para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel.PHP_EOL, FILE_APPEND);      
                
                return false;
            }

            //un solo vendedor, nosotros, marcamos como pvp_exportado el pvp de publicacion y "limpiamos" los campos de otros vendedores
            $this->otros_vendedores_min_pvp_nombre = '';
            $this->otros_vendedores_pvp_min = 0;

            //obtenemos el pvp de publicación que queremos poner como exportado al ser los únicos vendedores
            if (!$this->getPVPs()) {
                $this->resetProductoTablaOferta('No se obtuvieron pvps para el producto'); 

                return false;
            }

            $this->pvp_exportado = $this->pvp_publicacion;
            $this->friki_buybox = 1;
            $this->pvp_buybox = $this->ofertas_producto['offers'][0]['total_price'];

        } else {
            //más de un vendedor, miramos quien tiene la buyboxHasta mejor método, revisamos que el nombre coincida, con y sin tilde en la i
            if (($this->ofertas_producto['offers'][0]['shop_name'] == 'La Frikilería') || ($this->ofertas_producto['offers'][0]['shop_name'] == 'La Frikileria')) {
                //tenemos buybox, tenemos que sacar el siguiente vendedor más barato, sabemos que es la segunda posición del array de ofertas
                //07/08/2024 metemos pSQL porque han aparecido nombres con comillas etc              
                $this->otros_vendedores_min_pvp_nombre = pSQL($this->ofertas_producto['offers'][1]['shop_name']);  
        
                $this->otros_vendedores_pvp_min = $this->ofertas_producto['offers'][1]['total_price'];

                $this->friki_buybox = 1;

                $this->pvp_buybox = $this->ofertas_producto['offers'][0]['total_price'];

                //sacamos nuestros pvps para calcular si podemos modificar nuestro pvp a exportar en función del segundo pvp más barato
                if (!$this->getPVPs()) {
                    $this->resetProductoTablaOferta('No se obtuvieron pvps para el producto'); 
    
                    return false;
                }

                if ($this->otros_vendedores_pvp_min > $this->pvp_publicacion) {
                    $this->pvp_exportado = $this->pvp_publicacion;
                } elseif (($this->otros_vendedores_pvp_min <= $this->pvp_publicacion) && (($this->otros_vendedores_pvp_min - 0.05) > $this->pvp_minimo)) {
                    $this->pvp_exportado = $this->otros_vendedores_pvp_min - 0.05;
                } else {
                    //si el pvp minimo del otro vendedor no es mayor que nuestro pvp de publicacion ni está por encima de nuestro pvp minimo restándole 5 centimos, es posible que nuestro pvp minimo haya cambiado desde la última vez que bajamos ofertas activas (o en esa última vez), si no no tendríamos la buybox, de modo que aunque quizás perdamos la buybox, ponemos como pvp a exportar el pvp minimo
                    $this->pvp_exportado = $this->pvp_minimo;

                    if ($this->pvp_minimo < $this->pvp_buybox) {
                        $this->mensajes[] = "Posible perdida de Buybox por ¿cambio de pvp mínimo? para sku_mirakl = ".$this->sku_mirakl." en marketplace ".ucfirst($this->marketplace)." canal ".$this->channel;            

                        file_put_contents($this->log_file, date('Y-m-d H:i:s')." - Posible perdida de Buybox por ¿cambio de pvp mínimo? para sku_mirakl = ".$this->sku_mirakl." en marketplace ".ucfirst($this->marketplace)." canal ".$this->channel.PHP_EOL, FILE_APPEND); 
                    }                    
                }   
                
            } else {
                //no tenemos la buybox, calculamos qué pvp podemos exportar
                //07/08/2024 metemos pSQL porque han aparecido nombres con comillas etc              
                $this->otros_vendedores_min_pvp_nombre = pSQL($this->ofertas_producto['offers'][0]['shop_name']);  
        
                $this->otros_vendedores_pvp_min = $this->ofertas_producto['offers'][0]['total_price'];

                $this->friki_buybox = 0;

                $this->pvp_buybox = $this->ofertas_producto['offers'][0]['total_price'];

                //sacamos nuestros pvps para calcular hasta donde podemos bajar nuestro pvp a exportar para ganar o acercarnos lo máximo a la buybox
                if (!$this->getPVPs()) {
                    $this->resetProductoTablaOferta('No se obtuvieron pvps para el producto'); 
    
                    return false;
                }

                //nuestros pvps podrían haber cambiado en la última bajada de ofertas activas, de modo que debemos comparar todos
                if ($this->pvp_buybox > $this->pvp_publicacion) {
                    $this->pvp_exportado = $this->pvp_publicacion;
                } elseif (($this->pvp_buybox <= $this->pvp_publicacion) && (($this->pvp_buybox - 0.05) > $this->pvp_minimo)) {
                    $this->pvp_exportado = $this->pvp_buybox - 0.05;
                } else {
                    $this->pvp_exportado = $this->pvp_minimo;
                }
                
            }
        }

        //29/07/2024 hacemos una prueba ñapa para enviar a tradeinn siempre el pvp_minimo, independientemente de los resultados
        //12/08/2024 le pongo un 5% extra
        if ($this->marketplace == 'tradeinn') {
            $this->pvp_exportado = $this->pvp_minimo*1.05;
        }        

        $this->updateTablaOferta();        
        
        return true;
    }

    //función que obtiene y prepara los datos necesarios para exportar vía api el pvp, stock etc del producto
    //25/06/2024 Se produce un error, que cuando se acaba el stock de un productoen prestashop y sigue activo en mirakl, en este punto, si hay varios canales en el marketplace, se exportará como sin stock, lo cual lo desactivará en mirakl para todos los canales, y al procesar el siguiente/s canal/es, La frikilería no aparecerá como vendedor, al no tener la oferta activa, generando un error. Para solucionarlo, como sabemos que si vamos a exportar stock 0 se va a desactivar, permitimos la primera exportación, que será para el canal principal, y que actualizará el stock y desactivará la oferta, y después marcamos active = 0 a la oferta para todos los canales del marketplace, de modo que ya no saldrá para su proceso hasta que el proceso que exporta los productos en csv no lo veulva a incluir cuando vuelva a tener stock.
    public function preparaOfertaJSON() {
        //reseteamos array de producto
        $this->array_oferta_producto = array();
        //para enviar los pvps vía api por cada sku y canal-marketplace insertamos aquí los datos al json $json_oferta_producto. Para ello es obligatorio incluir el pvp "base" es decir, el price que coincide con el pvp del canal principal del marketplace. Además hay que incluir el stock. Necesitamos exportar también los campos específicos de cada marketplace en el json. Necesitamos obtener de Prestashop su referencia, stock y en función de este y si es campo específico, el leadtime to ship, o el iva etc. En este punto tenemos el id_product e id_product_attribute y referencia en lafrips_mirakl_ofertas, con eso podemos sacar el resto de datos de Prestashop

        //04/12/2024 Sacamos si el producto tiene la categoría NO SUBIR MIRAKL, de modo que se siga exportando , pero se le pondrá stock 0, evitando que productos que ya estén en Mirakl se queden colgados si simplemente dejamos de exportarlos
        //03/02/2025 Para la latencia de productos como redstring y amont que ahora tenemos como dropshipping especial y hemos puesto su latency en mensaje_disponibilidad = 1, como al restarle 2 para sacar el supplier_leadtime_to_ship quedaría negativo, modificamos la sql para que devuelva 1 como mínimo utilizando GREATEST()
        // (IFNULL(med.latency, 7) - 2) AS supplier_leadtime_to_ship,
        $sql_info_producto = "SELECT mio.sku_prestashop AS sku_prestashop, ROUND(tax.rate, 0) AS 'tipo_iva', ava.out_of_stock AS permite_pedido_sin_stock,
        (ava.quantity - IFNULL((SELECT SUM(physical_quantity) FROM lafrips_stock 
            WHERE id_product = ava.id_product AND id_product_attribute = ava.id_product_attribute AND id_warehouse = 4),0)) AS quantity,
        GREATEST(IFNULL(med.latency, 7) - 2, 1) AS supplier_leadtime_to_ship, pro.id_supplier AS id_supplier, mar.out_of_stock AS marketplace_out_of_stock,
        mar.campos_especificos AS marketplace_campos_especificos,
        mar.additional_fields AS marketplace_additional_fields,
        IF((SELECT id_product FROM lafrips_category_product WHERE id_category = ".$this->categoria_no_mirakl." AND id_product = pro.id_product),1,0) AS no_subir_mirakl   
        FROM lafrips_mirakl_ofertas mio
        JOIN lafrips_product pro ON pro.id_product = mio.id_product    
        JOIN lafrips_stock_available ava ON ava.id_product = mio.id_product AND ava.id_product_attribute = mio.id_product_attribute
        JOIN lafrips_tax_rule tar ON pro.id_tax_rules_group = tar.id_tax_rules_group AND tar.id_country = 6
        JOIN lafrips_tax tax ON tax.id_tax = tar.id_tax   
        JOIN lafrips_mirakl_marketplaces mar ON mar.marketplace = mio.marketplace
        LEFT JOIN lafrips_mensaje_disponibilidad med ON med.id_supplier = pro.id_supplier AND med.id_lang = 1  
        WHERE mio.sku_mirakl = '".$this->sku_mirakl."'
        AND mio.marketplace = '".$this->marketplace."'
        AND mio.channel = '".$this->channel."'";

        if (!$info_producto = Db::getInstance()->getRow($sql_info_producto)) {
            $this->error = 1;
            $this->mensajes[] = "No se pudo obtener información para exportar vía API para sku_mirakl ".$this->sku_mirakl." para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel;            

            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - No se pudo obtener información para exportar vía API para sku_mirakl ".$this->sku_mirakl." para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel.PHP_EOL, FILE_APPEND);     
            
            return false;
        }   

        //preparamos las variables a enviar en el json        

        //available-start-date y available-end-date las tenemos en $this->hoy_menos_1 y $this->hoy_mas_10        

        //tenemos que asignar el stock si el producto no tuviera y es de permitir pedido según el marketplace. Primero, si es negativo (hemos sacado el disponible online) lo ponemos a 0
        if ($info_producto['quantity'] < 0) {
            $info_producto['quantity'] = 0;
        }

        //si el marketplace permite venta sin stock y el proveedor está configurado como admitido y el producto tiene permitir pedido, ponemos stock 999
        if ($info_producto['marketplace_out_of_stock'] && !$info_producto['quantity'] && $info_producto['permite_pedido_sin_stock'] == 1 && in_array($info_producto['id_supplier'], $this->proveedores_sin_stock)) {
            //se permite venta sin stock, enviamos 999
            $info_producto['quantity'] = 999;
        }  

        //04/12/2024 Si el producto tiene la categoria NO SUBIR MIRAKL, pondremos su stock a 0
        if ($info_producto['no_subir_mirakl']) {
            $info_producto['quantity'] = 0;
        }

        //ahora tenemos que sacar el pvp "price" que es el del canal principal. Si estamos en el canal principal, será $this->pvp_exportado ya que es el mismo, si no  hay que sacar el pvp_exportado de lafrips_mirakl_ofertas para el marketplace-canal_principal-sku_mirakl ya que al hacer el proceso pasando por orden primero por el canal principal, el pvp que haya será el actual, es decir, si estamos en un canal no principal ya hemos pasado por el principal       
        if ($this->channel_principal) {
            $price = $this->pvp_exportado;
        } else {
            //no estamos en canal principal, sacamos el pvp_exportado que se marcó al canal principal
            $sql_price = "SELECT mio.pvp_exportado 
            FROM lafrips_mirakl_ofertas mio
            JOIN lafrips_mirakl_channels mic ON mic.channel_code = mio.channel AND mic.principal = 1
            WHERE mio.sku_mirakl = '".$this->sku_mirakl."'
            AND mio.marketplace = '".$this->marketplace."'";

            $price = Db::getInstance()->getValue($sql_price);

            if (!$price) {
                $this->error = 1;
                $this->mensajes[] = "No se pudo obtener 'price' para exportar vía API para sku_mirakl ".$this->sku_mirakl." para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel;            

                file_put_contents($this->log_file, date('Y-m-d H:i:s')." - No se pudo obtener 'price' para exportar vía API para sku_mirakl ".$this->sku_mirakl." para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel.PHP_EOL, FILE_APPEND);     
                
                return false;
            }
        }        

        //decodificamos el json de campos específicos de marketplace almacenado en tabla a array PHP
        $campos_especificos = json_decode($info_producto['marketplace_campos_especificos'], true);        

        //ahora, si hay campos específicos añadimos los values al array array_campos_especificos
        //los campos específicos, específicos de algunos marketplaces se pueden enviar con la API, por ejemplo, canon y tipo-iva de PCcomponentes pero metiéndolos dentro de offer_additional_fields y con guión en medio, de otra manera. Damos por hecho que como la primera vez que se exportan es con csv donde si acpeta el campo, el producto ya lo tendría con sus valores, dado que esos no cambian. Mientras que leadtime-to-ship si que se puede actualizar con la API
        if (!empty($campos_especificos)) {
            $array_campos_especificos = array();
            foreach ($campos_especificos AS $key => $value) {
                //cambiamos el guión por guión bajo ya que la API lo espera así
                $key = str_replace("-", "_", $key);
                //si es tipo-iva lo hemos sacado en la consulta
                if ($key == 'leadtime_to_ship') {
                    //el leadtime-to-ship lo hemos sacado para el proveedor por defecto del producto, y se pone ese si es venta sin stock, o 1 por defecto si hay stock físico. Si se vende sin stock 'quantity' será 999 en este punto. Si no hay stock da igual porque no saldrá a la venta
                    if ($info_producto['quantity'] == 999) {
                        $array_campos_especificos[$key] = $info_producto['supplier_leadtime_to_ship'];
                    } else {                    
                        $array_campos_especificos[$key] = 1;
                    }                    
                } else {
                    //valores fijos
                    $array_campos_especificos[$key] = $value;
                }                
            }
        }

        //sacamos additional fields, si los hay
        $additional_fields = json_decode($info_producto['marketplace_additional_fields'], true);

        if (!empty($additional_fields)) {
            $array_additional_fields = array();
            foreach ($additional_fields AS $key => $value) {
                //aquí parece que no hay que cambiar el guión por guión bajo                
                //si es tipo-iva lo hemos sacado en la consulta
                if ($key == 'tipo-iva') {
                    $array_additional_fields[$key] = $info_producto['tipo_iva'];
                } elseif ($key == 'fulfillment-latency') {
                    //al fulfillment-latency de momento le ponemos el valor de leadtime-to-ship 
                    if ($info_producto['quantity'] == 999) {
                        $array_additional_fields[$key] = $info_producto['supplier_leadtime_to_ship'];
                    } else {                    
                        $array_additional_fields[$key] = 1;
                    }                    
                } else {
                    //valores fijos, por ejemplo canon = 0 para pccomponentes o strike-price-type para mediamarkt
                    $array_additional_fields[$key] = $value;
                }                
            }
        }

        //tenemos que generar un json por producto, que luego se insertará en el json con todos los productos a exportar (100max por llamada a api OF24) con el siguiente formato, teniendo en cuenta que campos específicos (en el ejemplo es tradeinn, espcifico el leadtiem_to_ship) debe ser precisamente, específico, dependiendo del marketplace. también hay un eejmplo de como poner los campos adicionales, propios de cada amrketplace, aunque no pertenezca al ejemplo (fulfillment-latency)
        /*
        {      
            "available_ended": "2024-07-18T12:00:00Z",
            "available_started": "2024-06-18T12:00:00Z",
            "leadtime_to_ship": 2, 
            "price": 17.90,  
            "offer_additional_fields": [
                {
                    "code": "fulfillment-latency",
                    "value": "2"
                }
            ],  
            "all_prices": [        
              {
                "channel_code": "DE",          
                "unit_origin_price": 21.30
              }
            ], 
            "quantity": "699",
            "state_code": "11",
            "shop_sku": "MAR24020358",      
            "update_delete": "update"
          }
        */

        $this->array_oferta_producto = [
            "available_started" => $this->hoy_menos_1,  
            "available_ended" => $this->hoy_mas_10,  
            "quantity" => $info_producto['quantity'],  
            "state_code" => 11,  
            "price" => $price,     
            "all_prices" => [
                ["channel_code" => $this->channel,
                "unit_origin_price" => $this->pvp_exportado]
            ],                   
            "shop_sku" => $info_producto['sku_prestashop'],                                                     
            "update_delete" => "update"
        ];

        //añadimos los campos específicos
        if (!empty($campos_especificos)) {
            foreach ($array_campos_especificos AS $key => $value) {
                $this->array_oferta_producto[$key] = $value;
            }
        }

        //añadimos los additional_fields, que llevan otro formato más compuesto
        /*
        "offer_additional_fields": [
            {
            "code": "fulfillment-latency",
            "value": "2"
            }
        ],  
        */
        if (!empty($additional_fields)) {
            $this->array_oferta_producto['offer_additional_fields'] = array();
            $array_additional_field = array();
            foreach ($array_additional_fields AS $key => $value) {
                $array_additional_field = array(
                    "code" => $key,
                    "value" => $value
                );

                $this->array_oferta_producto['offer_additional_fields'][] = $array_additional_field;
            }
        }

        //25/06/2024 Comprobamos el stock que se va a exportar, si es 0 el producto se desactivará en Mirakl, de modo que lo vamos a marcar ahora como active = 0 en lafrips_mirakl_ofertas para evitar que el proceso lo veulva a sacar para otros canales si los hay.
        if ($info_producto['quantity'] == 0) {
            $this->desactivaProductoTablaOferta();
        }        

        return true;
    }

    //función que busca los campos pvp_minimo y pvp_publicacion de un producto-marketplace-canal
    public function getPVPs() {
        $sql_select_pvps = "SELECT pvp_minimo, pvp_publicacion
        FROM lafrips_mirakl_ofertas
        WHERE marketplace = '".$this->marketplace."'
        AND channel = '".$this->channel."'
        AND sku_mirakl = '".$this->sku_mirakl."'";        
           
        if (!$pvps = Db::getInstance()->getRow($sql_select_pvps)) {
            $this->error = 1;
            $this->mensajes[] = "No se pudieron obtener los pvps para sku_mirakl = ".$this->sku_mirakl." en marketplace ".ucfirst($this->marketplace)." canal ".$this->channel;            

            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - No se pudieron obtener los pvps para sku_mirakl = ".$this->sku_mirakl." en marketplace ".ucfirst($this->marketplace)." canal ".$this->channel.PHP_EOL, FILE_APPEND);     
            
            return false;
        }   

        $this->pvp_minimo = $pvps['pvp_minimo'];
        $this->pvp_publicacion = $pvps['pvp_publicacion'];

        return true;
    }

    //función que recoge un bloque de skus de mirakl para solicitar sus ofertas, limitado a 100 skus, separadas por coma, por marketplace y canal
    public function getSkus() {
        $sql_select = "SELECT sku_mirakl
        FROM lafrips_mirakl_ofertas
        WHERE marketplace = '".$this->marketplace."'
        AND channel = '".$this->channel."'
        AND active = 1
        AND error = 0
        AND ignorar = 0
        AND check_pvp = 0
        LIMIT 100";
           
        if (!$productos_tabla_mirakl_ofertas = Db::getInstance()->ExecuteS($sql_select)) {
            // como no es necesariamente un error sino simplemente que no hay más productos no lo metemos
            // $this->error = 1;
            // $this->mensajes[] = "No se pudieron obtener o no hay sin procesar los productos de lafrips_mirakl_ofertas para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel;            

            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - No se pudieron obtener o no hay sin procesar los productos de lafrips_mirakl_ofertas para marketplace ".ucfirst($this->marketplace)." canal ".$this->channel.PHP_EOL, FILE_APPEND);     
            
            return false;
        }   

        $array_skus = array();

        foreach ($productos_tabla_mirakl_ofertas AS $producto) {
            $array_skus[] = $producto['sku_mirakl'];
        }

        //guardamos las skus separadas por coma en $this->skus
        $this->skus = implode(",", $array_skus);

        return true;
    }

    public function apiP11OfertasProducto() {  
        //Parece que para obtener el orden correcto de las ofertas por total_price, en el caso de haber varios canales,hay que especificar elcódigo de canal tanto en el parámetro channel_codes como en pricing_channel_codes, dado que he visto algún caso en que por defecto para worten parece sacar el total_price de Portugal y ordena por ese precio, y si es más barato en ES puede no tener el orden correcto

        $parameters = 'offer_state_codes=11&product_ids='.$this->skus.'&channel_codes='.$this->channel.'&pricing_channel_code='.$this->channel;

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/products/offers?'.$parameters,
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

            $error_message = 'Error API Mirakl P11 para petición ofertas de productos para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

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

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API P11 a petición de ofertas de productos para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' no es correcta'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API P11 a petición de ofertas de productos para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' no es correcta'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Message: '.$mensaje_error;        
                
                if (($http_code > 399) && ($http_code < 500)) {
                    //Error 4xx suele ser un error por parte del solicitante, de modo que quizás si se da el caso es mejor no insistir. Si se trata de un error 400 - Channel(s) do(es) not exist o error 429 - Too Many Requests, no queremos continuar con el canal. Si es 429 probablemente no nos deje continuar en ningún caso. Para que el proceso salga del do while lo que hacemos antes de vovler es marcar como check_pvp a 1 a todo el canal en proceso para evitar un loop infinito
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ATENCIÓN, Error recibido impide continuar con canal, marcamos check_pvp = 1 a todos los productos de canal '.$this->channel.PHP_EOL, FILE_APPEND); 

                    $this->mensajes[] = 'ATENCIÓN, Error recibido impide continuar con canal, marcamos check_pvp = 1 a todos los productos de canal '.$this->channel;

                    $sql_update = "UPDATE lafrips_mirakl_ofertas
                    SET
                    check_pvp = 1                    
                    WHERE marketplace = '".$this->marketplace."'
                    AND channel = '".$this->channel."'";
                    
                    Db::getInstance()->execute($sql_update);

                }

                return false;
            }

            //si la llamada es correcta , vamos a pasar el json de respuesta a array de PHP para simplificar el trabajo. De dicho array nos interesa 'products' (no contiene nada más pero viene como un array dentro de un array)
            $this->ofertas_productos = json_decode($response, true)['products'];   
            
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API P11 a petición de ofertas de productos para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' correcta'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);                                        

            return true;                                   

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API P11 a petición /api/products/offers de ofertas de productos para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel; 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }
    }

    //función que "exporta" las ofertas de x productos (hasta 100 generalmente) con su stock, pvp etc
    //24/07/2024 en lugar de enviar solo 100 como haciamos limitados por API P11 que solo permite descargar 100 ofertas a la vez, acumulamos los productos y ahora enviamos todas las ofertas al mismo tiempo en una sola llamada por canal
    public function apiOF24OfertasProducto() {
        $this->import_id = "";

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/offers',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $this->json_ofertas_productos,
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

            $error_message = 'Error API Mirakl OF24 para petición api/offers para marketplace '.ucfirst($this->marketplace).' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

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

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API OF24 a petición api/offers para marketplace '.ucfirst($this->marketplace).' no es correcta'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API OF24 a petición api/offers para marketplace '.ucfirst($this->marketplace).' no es correcta'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Message: '.$mensaje_error;                

                return false;
            }

            //si la llamada es correcta devuelve un código de importación, que con otra API OF02 muestra el resultado y si hay error se puede ver el informe con API OF03
            $this->import_id = $response_decode->import_id;

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API OF24 a petición api/offers para marketplace '.ucfirst($this->marketplace).' correcta'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Código de importación recibido - import_id = '.$this->import_id.PHP_EOL, FILE_APPEND);

            return true;            

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API OF24 a petición api/offers para marketplace '.ucfirst($this->marketplace); 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }
    }

    //función que con el import_id recibido en la llamda a API OF24 comprueba si existe un informe de errores
    public function apiOF03ErrorReport() {        

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/offers/imports/'.$this->import_id.'/error_report',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',            
            CURLOPT_HTTPHEADER => array(
                'Authorization: '.$this->shop_key,
                'Accept: application/octet-stream'                
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

            $error_message = 'Error API Mirakl OF03 para petición informe error después de OF24 para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        if ($response) { 
            
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            //pasamos el JSON de respuesta a un objeto PHP. 
            // $response_decode = json_decode($response);          
            
            //en este caso la respuesta correcta es status 404 not found, porque indica que no existe informe de errores, en todo caso si existe lo volvamos a log y seguimos

            if ($http_code != 404) {                

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Mirakl OF03 para petición informe error después de OF24 para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' indica existencia de ERROR en la exportación con import_id: '.$this->import_id.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR Message: '.$response.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API Mirakl OF03 para petición informe error después de OF24 para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' indica existencia de ERROR en la exportación con import_id: '.$this->import_id; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'ERROR Message: '.$response;      

                return false;
            }

            //si la llamada es correcta, es decir, 404 no hay errores, continuamos             
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API Mirakl OF03 para petición informe error después de OF24 para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' correcta'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);  
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Llamada API OF24 sin errores en la exportación con import_id: '.$this->import_id.PHP_EOL, FILE_APPEND);                                        

            return true;                                   

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, respuesta sin response de la API Mirakl OF03 para petición informe error después de OF24 para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' en la exportación con import_id: '.$this->import_id.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);  

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API Mirakl OF03 para petición informe error después de OF24 para marketplace '.ucfirst($this->marketplace).', canal '.$this->channel.' en la exportación con import_id: '.$this->import_id; 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }
    }

    //función para hacer update a línea de producto-marketplace-canal con lo obtenido trás analizar las ofertas del producto
    public function updateTablaOferta() {   
        $sql_update = "UPDATE lafrips_mirakl_ofertas
        SET
        check_pvp = 1,	
        pvp_exportado = ".$this->pvp_exportado.",	
        friki_buybox = ".$this->friki_buybox.",
        buybox = ".$this->pvp_buybox.",
        date_buybox = NOW(),  
        total_vendedores = ".$this->total_vendedores.",	
        otros_min_pvp = ".$this->otros_vendedores_pvp_min.",
        otros_min_pvp_nombre = '".$this->otros_vendedores_min_pvp_nombre."',	
        date_upd = NOW()
        WHERE sku_mirakl = '".$this->sku_mirakl."' 
        AND marketplace = '".$this->marketplace."'
        AND channel = '".$this->channel."'";

        // echo 'sql: <br>'.$sql_update;
        
        if (!Db::getInstance()->execute($sql_update)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error haciendo update de pvp ofertas en marketplace '.$this->marketplace.', canal '.$this->channel.' para  sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error haciendo update de pvp ofertas en marketplace '.$this->marketplace.', canal '.$this->channel.' para  sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas';
        }

        return;
    }

    //función que marca como active = 0 las ofertas de un producto concreto para todos los canales de un marketplace
    public function desactivaProductoTablaOferta() {   
        $sql_desactiva = "UPDATE lafrips_mirakl_ofertas
        SET
        active = 0,
        last_date_deactivated = NOW(),
        check_pvp = 1,	        
        date_upd = NOW()
        WHERE sku_mirakl = '".$this->sku_mirakl."' 
        AND marketplace = '".$this->marketplace."'";        
        
        if (!Db::getInstance()->execute($sql_desactiva)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error haciendo update en desactivaProductoTablaOferta en marketplace '.$this->marketplace.', canal '.$this->channel.' para  sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error haciendo update en desactivaProductoTablaOferta en marketplace '.$this->marketplace.', canal '.$this->channel.' para  sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas';
        }

        return;
    }

    //función para hacer update a línea de producto-marketplace-canal cuando el producto no tenía vendedores o nosotros no estabamos entre ellos. Puede suceder si el producto se desactiva entre la ejecución del proceso de ofertas activas y el de pvp
    public function resetProductoTablaOferta($error_message) {   
        $sql_update = "UPDATE lafrips_mirakl_ofertas
        SET
        active = 0,
        last_date_deactivated = NOW(),
        check_pvp = 1,	
        pvp_exportado = pvp_publicacion,
        friki_buybox = 0,
        buybox = ".$this->otros_vendedores_pvp_min.",
        date_buybox = NOW(),        
        total_vendedores = ".$this->total_vendedores.",	
        otros_min_pvp = ".$this->otros_vendedores_pvp_min.",
        otros_min_pvp_nombre = '".$this->otros_vendedores_min_pvp_nombre."',	
        error_message = CONCAT(error_message, ' | $error_message - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),   
        date_upd = NOW()
        WHERE sku_mirakl = '".$this->sku_mirakl."' 
        AND marketplace = '".$this->marketplace."'
        AND channel = '".$this->channel."'";
        
        if (!Db::getInstance()->execute($sql_update)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error haciendo update en resetProductoTablaOferta en marketplace '.$this->marketplace.', canal '.$this->channel.' para  sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error haciendo update en resetProductoTablaOferta en marketplace '.$this->marketplace.', canal '.$this->channel.' para  sku_mirakl = '.$this->sku_mirakl.' en tabla lafrips_mirakl_ofertas';
        }

        return;
    }

    public function resetCheckPVP() {
        $sql_update = "UPDATE lafrips_mirakl_ofertas
        SET
        check_pvp = 0";
        
        if (!Db::getInstance()->execute($sql_update)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error reseteando pvp_check en tabla lafrips_mirakl_ofertas'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error reseteando pvp_check en tabla lafrips_mirakl_ofertas';

            return false;
        }

        return true;
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
        $this->log_file = $this->log_path.'proceso_exportar_pvp_buybox_ofertas_marketplaces_mirakl_'.date('Y-m-d H:i:s').'.txt';
                   
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso para BuyBox de productos para marketplaces Mirakl'.PHP_EOL, FILE_APPEND);  
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Tiempo máximo ejecución - my_max_execution_time = '.$this->my_max_execution_time.PHP_EOL, FILE_APPEND);     

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

    public function setError($error_message) {
        $error_message = pSQL($error_message);

        $sql_update = "UPDATE lafrips_mirakl_ofertas
        SET 
        error = 1,
        date_error = NOW(), 
        error_message = CONCAT(error_message, ' | $error_message - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),    
        date_upd = NOW()
        WHERE sku_mirakl = '".$this->sku_mirakl."'
        AND marketplace = '".$this->marketplace."'
        AND channel = '".$this->channel."'";

        if (!Db::getInstance()->execute($sql_update)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Error marcando Error en tabla lafrips_mirakl_ofertas. Mensaje error = '.$error_message.' - sku_mirakl = '.$this->sku_mirakl.', marketplace = '.$this->marketplace.', channel = '.$this->channel.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - Error marcando Error en tabla lafrips_mirakl_ofertas. Mensaje error = '.$error_message.' - sku_mirakl = '.$this->sku_mirakl.', marketplace = '.$this->marketplace.', channel = '.$this->channel;
        }

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

        $asunto = 'ERROR procesando ofertas de marketplaces Mirakl para BuyBox '.date("Y-m-d H:i:s");
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


