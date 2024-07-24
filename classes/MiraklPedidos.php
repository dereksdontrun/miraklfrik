<?php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');
//para sacar la cookie caundo ejecutamos crear pedido manual
include_once(dirname(__FILE__).'/../miraklfrik.php');

//https://lafrikileria.com/modules/miraklfrik/classes/MiraklPedidos.php?cron=true

//https://lafrikileria.com/test/modules/miraklfrik/classes/MiraklPedidos.php?crear_pedido=true&order_id=123654_2&marketplace=worten

//26/06/2024 Clase con la que pediremos los pedidos entrantes a cada marketplace, comprobaremos su viabilidad, en cuyo caso confirmaremos las líneas de pedido a Mirakl, recopilaremos la info enviándola al Webservice para generar el nuevo pedido. También revisará los pedidos en Prestashop a los que se vaya generando etiqueta de envío para actualizarlo en Mirakl y finalmente darlos por envíados.

//01/07/2024 En algunos casos los pedidos no muestran su dirección de entrega hasta que no han sido aceptados, de modo que el proceso debe ser:
// 1 - Buscar pedidos en estado WAITING_ACCEPTANCE (API OR11), y aceptar sus líneas de pedido (API OR21). Guardamos datos básicos del pedido en lafrips_mirakl_orders con campo aceptado = 1
// 2 - En otra ejecución volver a buscar el estado del anterior pedido (API OR11). Si es SHIPPING es que está aceptado y cobrado. Se trata de revisar el pago y su paso a estado shipping, si es correcto tendremos acceso a los datos de cliente. Lo marcamos como revisado_shipping = 1 y lo creamos en Prestashop.
// 3 - En posteriores procesos se busca en la tabla lafrips_mirakl_orders los pedidos con revisado_shipping = 1 pero enviado = 0 y se comprueba su estado en Prestashop. Cuando estén enviados se recogerán sus datos de envío y enviarán a Mirakl (API OR23) y seguido se confirmará el envío (API OR24).

//Cada vez que hagamos algo con un pedido hay que marcarle procesando = 1 en lafrips_mirakl_orders para que si por error se ejcuta la tarea cron dos veces muy seguidas, no se esté trabajando sobre el mismo pedido (enviando datos a API o duplicándolo en Prestashop, etc) pasando preocesando a 0 al terminar el proceso en curso. Se pondrá la fecha para revisar los pedidos que lleven más de  x minutos en ese estado procesando = 1

//tendremos una ejecución del proceso cada 10 o 15 minutos. Hará todas las revisiones y después creará los pedidos pendientes y finalmente chequeará los que estén en procesando = 1, no debería haber ninguno al final del proceso.

//hay que preparar el proceso para que pueda recibir datos concretos de un pedido de Mirakl, su order_id y marketplace, para que el proceso revise el pedido concreto y lo envíe a crear, es decir, poder pedir crear pedidos concretos en Prestashop sin ejecutar todo lo demás.


// ini_set('error_log', _PS_ROOT_DIR_.'/modules/miraklfrik/log/error/php_error.log');

// // Turn on error reporting
// ini_set('display_errors', 1);
// // error_reporting(E_ALL); cambiamos para que no saque E_NOTICE
// error_reporting(E_ERROR | E_WARNING | E_PARSE | E_DEPRECATED | E_STRICT);

//la url de llamada debe llevar el parámetro cron = true, y el proceso a realizar, proceso 'revisar' para buscar pedidos nuevos de los marketplaces, confirmar pago y paso a estado shipping en mirakl, actualización de carrier y confirmación de envío. Proceso 'crear' para generar en Prerstashop los pedidos de Mirakl con SHIPPING confirmado
//  POR AHORA NO, UN SOLO PROCESO RECORRE TODO 
if (isset($_GET['cron']) && $_GET['cron'] == 'true') {    
    //proceso cron normal de repaso de estados, creación de pedidos etc
    $a = new MiraklPedidos();
    
} elseif (isset($_GET['crear_pedido']) && $_GET['crear_pedido'] == 'true') {   

    //proceso para crear un pedido concreto de Mirakl, debe recibir el order_id y el marketplace al que pertenece
    if (isset($_GET['order_id']) && $_GET['order_id'] != '' && isset($_GET['marketplace']) && $_GET['marketplace'] != '') {   
        //nos aseguramos de que existe el marketplace
        $order_id = $_GET['order_id'];  
        $marketplace = $_GET['marketplace']; 
        
        if (!Db::getInstance()->getValue("SELECT id_mirakl_marketplaces FROM lafrips_mirakl_marketplaces WHERE marketplace = '".$marketplace."'")) {
            exit;
        }
        
        $a = new MiraklPedidos($order_id, $marketplace);
        
    } else {
    
        exit;
        
    }    
    
} else {
    exit;
}

class MiraklPedidos
{           
    public $proceso;

    public $mensajes = array();

    public $error = 0;  

    public $log = true;        
    
    //para pruebas de creación de pedidos, enviará a entorno de test de lafrikileria.com
    public $test = false;

    //variable para el archivo a generar en el servidor con las líneas log
    public $log_file;   

    //carpeta de archivos log    
    public $log_path = _PS_ROOT_DIR_.'/modules/miraklfrik/log/pedidos/';      
    
    //carpeta de archivos de facturas de pedido    
    public $invoice_path = _PS_ROOT_DIR_.'/modules/miraklfrik/documents/';    

    //variable para almacenar la ruta completa de la factura, carpeta y nombre de archivo
    public $invoice_name;

    //para almacenar las credenciales para la conexión a la API según el Marketplace. Tendrá formato array($end_point, $api_key)
    //por ahora también meteré algunas variables por marketplace, por ejemplo, si exportar productos con venta sin stock o no, etc
    public $marketplaces_credentials = array();   
    
    //credenciales del usuario de Mirakl para el webservice/API de Prestashop
    public $webservice_credentials = array();    

    //variables donde se almacena el marketplace que estamos procesando, su out_of_stock, su modificacion_pvp
    public $marketplace;
    public $channel;
    public $channel_active;    
    public $end_point;
    public $shop_key;    

    //variable para guardar la info de marketplaces que sacaremos de lafrips_mirakl_marketplaces
    public $marketplaces;
    public $marketplace_channels;    

    public $pedidos_revisar;

    //id de pedido en Prestashop
    public $id_order;

    //id de pedido en Mirakl
    public $order_id;

    public $shipping_info;    

    public $prestashop_order_info;

    public $mirakl_order_info;

    public $webservice_order_info;

    //almacenaremos el json formateado a array, respuesta de API OR11 que obtiene info de un pedido o lista de pedidos
    public $respuesta_OR11;

    public $proceso_pedido_concreto = false;

    public $id_employee_manual;

    //variable para el cambio de moneda, por defecto será 1 ya que casi todo será en EUR, pero podría entrar algún pedido con otra moneda. Esta debe existir en frik_amazon_reglas para recoger el cambio, que se aplicaría a todos los precios. De momento queremos todos los pedidos en EUR por defecto al crearlos.
    public $cambio = 1;
          

    public function __construct($order_id = null, $marketplace = null) {      

        date_default_timezone_set("Europe/Madrid");        
        
        if ($order_id !== null && $marketplace !== null) {
            //primero comprobamos que estemos en un navegador con login hecho en Frikilería
            // Revisamos la cookie para saber si el usuario ha hecho login
            $cookie = new Cookie('psAdmin', '', (int)Configuration::get('PS_COOKIE_LIFETIME_BO'));

            // Recogemos el token del módulo para compararlo con el que se enví­a desde el back
            Tools::getAdminToken('miraklfrik'.(int)Tab::getIdFromClassName('MiraklPedidos').(int)$cookie->id_employee);

            // Vemos si el usuario ha hecho login
            if (empty($cookie->id_employee) || $cookie->id_employee == 0) {
                echo 'ATENCIÓN: DEBES HACER LOGIN EN LAFRIKILERIA.COM';

                exit;
            } else {
                $this->id_employee_manual = $cookie->id_employee;
            }

            //vamos a crear un pedido concreto de un marketplace concreto, no seguimos el resto del proceso
            $this->proceso_pedido_concreto = true;

            $this->order_id = $order_id;

            $this->marketplace = $marketplace;

            $this->crearPedidoConcreto();

            exit;
        }        

        //preparamos log        
        $this->setLog();   

        if (!$this->getWebserviceCredentials()) {
            $this->enviaEmail();

            exit;
        }

        if (!$this->getCredentials()) {
            $this->enviaEmail();

            exit;
        }          
        
        if (!$this->getMarketplaces()) {
            $this->enviaEmail();

            exit;
        }

        //recorreremos cada Marketplace realizando las diferentes revisiones y generando al final los pedidos nuevos
        foreach ($this->marketplaces AS $marketplace) {                 
            //preparamos las variables del marketplace
            $this->marketplace = $marketplace['marketplace'];          

            //url endponit y shop_key sacamos de credentials            
            $this->end_point = $this->marketplaces_credentials[$this->marketplace]['url'];

            $this->shop_key = $this->marketplaces_credentials[$this->marketplace]['shop_key'];

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Marketplace '.ucfirst($this->marketplace).' - Proceso '.ucfirst($this->proceso).PHP_EOL, FILE_APPEND);

            $this->procesaMarketplace();                    
            
        }     
        
        //comprobamos si hay pedidos que hayan quedado en procesando = 1 en esta o anterior ejecución para avisar, resetear o lo que sea
        $this->checkProcesando();

        if ($this->error) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s')." - PROCESO FINALIZADO CON ERRORES".PHP_EOL, FILE_APPEND);   

            $this->enviaEmail();
        }

        exit;
        
    }   
    
    //función que procesa la creación de un único pedido dado su Mirakl order_id y su marketplace, que tenemos en $this->order_id y $this->marketplace. Para crear un pedido concreto vamos a comprobar si existe en la tabla lafrips_mirakl_orders y si no es así tendremos que insertar los datos básicos. Para el proceso manual admitimos pedidos en cualquier estado en Mirakl, OJO
    public function crearPedidoConcreto() {
        //preparamos un log específico para este proceso
        $this->setLog();   

        //comprobamos que el pedido no esté creado ya, pero que exista en la tabla lafrips_mirakl_orders. Si está creado deveulve null, si no devuelve el id de la tabla (insertando si es necesario)
        if (!$id_mirakl_orders = $this->checkMiraklOrders()) {
            //está creado
            exit;
        }

        if (!$this->getWebserviceCredentials()) {
            $this->enviaEmail();

            exit;
        }
        
        //obtenemos las credenciales 
        if (!$this->getCredentials()) {
            //problemas con las credenciales
            exit;
        } 

        //preparamos url endpoint y shop_key del marketplace para las APIs             
        $this->end_point = $this->marketplaces_credentials[$this->marketplace]['url'];
        $this->shop_key = $this->marketplaces_credentials[$this->marketplace]['shop_key']; 

        //pedimos a APIOR11 la info del pedido $this->order_id del marketplace. Si el proceso es correcto quedará en $this->respuesta_OR11
        if (!$this->getInfoAPIOR11('unico')) {
            //problemas recibiendo la info
            exit;
        }

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - El pedido se encuentra en estado de Mirakl '.$this->respuesta_OR11['orders'][0]['order_state'].PHP_EOL, FILE_APPEND); 

        //si el pedido está en SHIPPING en Mirakl lo marcaremos, no podemos crear más que pedidos que incluyan la dirección del cliente, es decir, deben estar aceptados, por tanto solo estados SHIPPING, SHIPPED, CLOSED, RECEIVED
        if ($this->respuesta_OR11['orders'][0]['order_state'] == 'SHIPPING') {   
            //lo marcamos revisado shipping ahora
            $this->updateMiraklOrders('revisado');
        }

        if (!in_array($this->respuesta_OR11['orders'][0]['order_state'], array('SHIPPING','SHIPPED','CLOSED','RECEIVED'))) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - El estado de pedido no permite crearlo dado que no dispone de dirección de entrega'.PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Estados compatibles: "SHIPPING", "SHIPPED", "CLOSED", "RECEIVED"'.PHP_EOL, FILE_APPEND);
            //no lo marcamos error cancelado ahora, podría estar pendiente de aceptación... veremos
            // $this->updateMiraklOrders('cancelado');

            exit; 
        }
        

        //para facilitar el código, metemos la info del pedido directamente en $this->mirakl_order_info   
        $this->mirakl_order_info = $this->respuesta_OR11['orders'][0];    

        $this->setProcesando($id_mirakl_orders, true);

        if (!$this->crearPedido()) {
            //no se ha podido crear.
            $this->setProcesando($id_mirakl_orders, false);

            exit;
        }   
        
        //se ha creado el pedido y actualizamos la tabla mirakl_orders, si es que procede¿? en este proceso.
        //comprobamos que esté en la tabla
        $this->updateMiraklOrders('creado');
        
        exit;         
    }

    //función que comprueba si un pedido existe ya en la tabla lafrips_mirakl_orders y si está creado. Devolverá el id de la tabla si existe pero no está creado, lo insertará si no está en la tabla, devolviendo el id, y devolverá false si existe y ya está creado
    public function checkMiraklOrders() {
        $order = Db::getInstance()->getRow('SELECT id_mirakl_orders, id_order FROM lafrips_mirakl_orders WHERE order_id = "'.$this->order_id.'" AND marketplace = "'.$this->marketplace.'"');

        $id_order = $order['id_order'];

        if ($order['id_mirakl_orders'] == null) {
            //no está en tabla       
            $sql_insert_mirakl_order = "INSERT INTO lafrips_mirakl_orders
            (order_id, marketplace, creado_manual, id_employee_manual, date_add) 
            VALUES
            ('".$this->order_id."', '".$this->marketplace."', 1, ".$this->id_employee_manual.", NOW())";

            if (!Db::getInstance()->execute($sql_insert_mirakl_order)) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR haciendo insert en lafrips_mirakl_orders para pedido manual order_id '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' - id_employee = '.$this->id_employee_manual.PHP_EOL, FILE_APPEND); 
        
                $this->error = 1;
            
                $this->mensajes[] = ' - ERROR haciendo insert en lafrips_mirakl_orders para pedido manual order_id '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' - id_employee = '.$this->id_employee_manual;

                return false;
            }                    

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' no existe en la tabla lafrips_mirakl_orders - Insertando datos - id_mirakl_orders = '.Db::getInstance()->Insert_ID().PHP_EOL, FILE_APPEND);

            return Db::getInstance()->Insert_ID();                            
            
        } elseif ($id_order > 0) {
            //está en tabla y ya tiene id_order
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR, el pedido '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' existe en Prestashop con id_order = '.$id_order.' - Interrumpida creación de pedido'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
                
            $this->mensajes[] = '- ERROR, el pedido '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' existe en Prestashop con id_order = '.$id_order.' - Interrumpida creación de pedido'; 
                            
            return false;
        } else {
            //el pedido está en la tabla pero no ha sido creado todavía (id_order = 0)
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' disponible para crear en tabla lafrips_mirakl_orders. - id_mirakl_orders = '.$order['id_mirakl_orders'].PHP_EOL, FILE_APPEND); 

            return $order['id_mirakl_orders'];
        }
    }

    //función que procesa pedidos para cada marketplace, recorriendo las diferentes tareas
    public function procesaMarketplace() {        

        //buscamos pedidos pendientes de confirmar
        $this->aceptarPedidos();

        //buscamos pedidos ya aceptados y confirmamos su estado en Mirakl. Si es SHIPPING lo crearemos en Prestashop
        $this->confirmarPedidosAceptados();

        //buscamos pedidos ya aceptados que existen en Prestashop y revisamos su estado, si están enviados actualizamos sus datos de shipping en Mirakl
        $this->confirmarShipping();       
        
        //buscamos pedidos enviados con sus datos de shipping actualizados en Mirakl y confirmamos su envío en Mirakl
        $this->confirmarEnvios(); 

        //buscamos pedidos confirmados en Mirakl, generamos su factura en Prestashop, almacenándola en el servidor y la exportamos a Mirakl
        $this->gestionarFactura(); 

        return;
    }

    //función para realizar el proceso de creación de nuevos pedidos. Tendremos el order_id de mirakl en $this->order_id y la info del pedido en $this->respuesta_OR11['orders'][0], que hemos pasado a $this->mirakl_order_info. Deberemos guardar la info del pedido creado en $this->prestashop_order_info, id_order, id_webservice_order, id_customer e id_address_delivery 
    //tenemos que organizar toda la info del pedido para enviar a la API del webservice de Prestashop preparada por mi. Lo haremos rellenando el array $this->webservice_order_info que después codificaremos a json para hacer un POST
    //hay que mirar total_price tanto de pedido como de línea de pedido (por si hay varios productos) y order_tax_mode, que puede ser TAX_INCLUDED o TAX_EXCLUDED . Si es included total_price será el precio con impuestos, sacamos el iva del país destino y calculamos con el lo que es total_rice sin impuestos, y si es excluded total_price  será sin impuestos y con el iva del pais calculamos el price con impuestos. Parece que depedne de la configuración de la plataforma, pero no sé si es configurable por nosotros. Se ve en API PC01, order_tax_mode. De lso que tenemos ahora todos son tax included menos pccomponentes.
    /*
    {  
        "origin": "lafrikileria.pt",
        "marketplace": "",
        "channel": "",
        "order": {
            "order_date": "2023-03-02 10:05:00",
            "external_order_id": "123456",
            "shipping_deadline": "2023-03-04 10:05:00",
            "urgent_delivery": "false",
            "language_iso": "pt",
            "currency_iso": "EUR",    
            "total_products_tax_excl": "27.685950",
            "total_products_tax_incl": "33.500000",
            "total_discounts_tax_excl": "0",
            "total_discounts_tax_incl": "0",
            "total_shipping_tax_excl": "3.223140",
            "total_shipping_tax_incl": "3.900000",
            "gift_wrapping": "true",
            "gift_message": "Felicidades",
            "total_wrapping_tax_excl": "0.413223",
            "total_wrapping_tax_incl": "0.500000",
            "total_paid_tax_excl": "31.322314",
            "total_paid_tax_incl": "37.900000"    
        },
        "order_details": [
            {
                "external_detail_id": "1234567",
                "external_id_product": "12345",
                "external_id_product_attribute": "0",
                "id_product": "17321",
                "id_product_attribute": "0",
                "quantity": "1",
                "customized": "false",  
                "customization_message": "",
                "tax_rate": "23",
                "unit_price_tax_excl": "13.223140",
                "unit_price_tax_incl": "16.000000",
                "discount": "false",
                "reduction_type": "",
                "reduction_percentage": "",
                "reduction_amount": "",
                "unit_price_with_reduction_tax_excl": "",
                "unit_price_with_reduction_tax_incl": ""      
            },
            {
                "external_detail_id": "1234568",
                "external_id_product": "12346",
                "external_id_product_attribute": "1234",
                "id_product": "23437",
                "id_product_attribute": "39785",
                "quantity": "1",
                "customized": "false",  
                "customization_message": "",
                "tax_rate": "23",
                "unit_price_tax_excl": "14.462810",
                "unit_price_tax_incl": "17.500000",
                "discount": "false",
                "reduction_type": "",
                "reduction_percentage": "",
                "reduction_amount": "",
                "unit_price_with_reduction_tax_excl": "",
                "unit_price_with_reduction_tax_incl": ""      
            }
        ],
        "customer": {
            "external_customer_id": "654321",
            "email": "sergiopt1@lafrikileria.com",
            "firstname": "Sergio",
            "lastname": "Frik"
        },
        "delivery_address": {
            "firstname": "Sergio",
            "lastname": "Frik",
            "company": "PruebasApi",
            "phone": "",
            "phone_mobile": "691306844",
            "address1": "Praça da Liberdade 126",
            "address2": "",    
            "city": "Oporto",
            "state": "Porto",
            "postcode": "4000-322",
            "country_iso": "PT",
            "other": "Un mensaje que no se para qué sirve."    
        }
    }
    */
    public function crearPedido() {   
        
        $this->webservice_order_info = array();

        //origen del pedido, de momento ponemos el marketplace seguido del canal. Para marketplaces como carrefour o leclerc, etc que solo usan canal INIT por defecto, lo metemos a mano, y no lo añadimos a origin
        if ($this->mirakl_order_info['channel']['code']) {
            $this->webservice_order_info['origin'] = $this->marketplace.'__'.$this->mirakl_order_info['channel']['code'];
        } else {
            $this->webservice_order_info['origin'] = $this->marketplace;
        }       

        //marketplace y canal
        $this->webservice_order_info['marketplace'] = $this->marketplace;
        $this->webservice_order_info['channel'] = $this->mirakl_order_info['channel']['code'] ? $this->mirakl_order_info['channel']['code'] : 'INIT';

        //order
        //fecha de entrada y creación en Mirakl, como viene en UTC la formateamos a Madrid/Europe. Se trata de coger la fecha en formato "2024-07-03T02:26:51Z", crear un objeto DateTime especificando zona UTC y esa fecha, y después cambiar la time zone a Europe/Madrid, y formatear a 'Y-m-d H:i:s'
        $order_date_utc = $this->mirakl_order_info['created_date'];
        $date = new DateTime($order_date_utc, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Europe/Madrid'));
        $order_date = $date->format('Y-m-d H:i:s');

        $this->webservice_order_info['order'] = array();

        $this->webservice_order_info['order']['order_date'] = $order_date;

        $this->webservice_order_info['order']['external_order_id'] = $this->mirakl_order_info['order_id'];

        //fecha máxima de envío, le damos formato como a order_date
        $order_shipping_date_utc = $this->mirakl_order_info['shipping_deadline'];
        $date_shipping = new DateTime($order_shipping_date_utc, new DateTimeZone('UTC'));
        $date_shipping->setTimezone(new DateTimeZone('Europe/Madrid'));
        $order_date_shipping = $date_shipping->format('Y-m-d H:i:s');

        $this->webservice_order_info['order']['shipping_deadline'] = $order_date_shipping;

        //si el pedido es urgente o no, hay que ver si vale interpretar shipping_type_code ¿¿¿¿¿¿¿¿¿?????????
        //por ahora hasta decidir qué métodos de envío habrá lo ponemos false. Debe se string, no boolean porque se codifica a json
        $this->webservice_order_info['order']['urgent_delivery'] = 'false';

        //language_iso. Habría que poner el iso del país de destino. Cada marketplace lo pone de una manera, Carrefour ni lo pone. Utilizamos una función para averiguarlo, tanto idioma como country. $isos es un array tipo array($language_iso, $country_iso, $id_country)
        if (!$isos = $this->getIsoCodes()) {
            return false;
        }

        $this->webservice_order_info['order']['language_iso'] = $isos[0];

        //la moneda en Mirakl no siempre es euro, a 09/07/2024 hemos añadido el marketplace Empik polaco y usan PLN con cambio diferente.        
        $this->webservice_order_info['order']['currency_iso'] = $this->mirakl_order_info['currency_iso_code'];
        //comprobamos que la moneda existe en frik_amazon_reglas, que es donde guardamos las correspondencias de códigos de canal y el cambio, sacando este último. Si no existe es un error, habría que añadirla en principio. 
        if (!$this->getCambio()) {
            return false;
        }

        //empezamos a meter coste de pedido. Primero averiguamos si en el coste total del pedido van incluidos los impuestos o no (la mayoría si, pero pccomponentes no). Lo tenemos también en un campo de la tabla mirakl_marketplaces pero lo buscamos aquí.
        $order_tax_mode = $this->mirakl_order_info['order_tax_mode'];

        //de momento en Mirakl no tenemos gastos de envío pero los tenemos en cuenta. El pago total del pedido es total_price en la rama del pedido (no order_line). Se comprueba shipping_price. Si hubiera algo se tendrá en cuenta. Y dependiendo de $order_tax_mode se sacará con y sin iva de una forma u otra, pero calcularemos también el iva aplicado. Como los productos pueden tener diferente iva hay que comprobar primero las lineas de pedido, sacar el iva de cada producto si hay más de uno, buscándolo en Prestashop con getIdsProducto($referencia_prestashop) y teniendo en cuenta el destino, y así se calcula el precio total. Recorremos las líneas de pedido preparando la info y vamos guardando el coste de los productos con y sin iva       

        $order_lines = $this->mirakl_order_info['order_lines'];

        // echo '<pre>';
        // print_r($order_lines);
        // echo '</pre><br><br>';

        $this->webservice_order_info['order_details'] = array();        
        $total_productos_con_iva = 0;
        $total_productos_sin_iva = 0;
        foreach ($order_lines AS $order_line) {
            $order_detail = array();
            //Existe la posibilidad de que, por malfuncionamiento de Mirakl (en worten pasa) si un pedido tiene un producto del que se piden varias unidades, el producto entra al pedido con una línea de pedido por producto. Debemos asegurarnos si esto sucede de unificarlo para que no de error al pasarlo a Prestashop. Para ello, en cada vuelta del foreach comprobamos si offer_id ya se encuentra en $this->webservice_order_info['order_details'], dado que lo guardamos como external_id_product. Sacamos con array_column() todos los key-value de los arrays internos de $this->webservice_order_info['order_details'] en su key external_id_product y con array_search buscamos en el array resultante el id. Si lo encuentra, devuelve el key del array dentro de $this->webservice_order_info['order_details'], y podremos sumar la unidad y duplicar los precios, pero de alguna manera perdemos el order_line_id
            $column_values = array_column($this->webservice_order_info['order_details'], 'external_id_product');
            $found_key = array_search($order_line['offer_id'], $column_values);
            //si hemos encontrado la referencia actualizamos los valores, si no metemos el producto como otra línea de pedido
            if ($found_key !== false) {
                //el producto está duplicado, sumamos unidades y multiplicamos precios por la cantidad
                $this->webservice_order_info['order_details'][$found_key]['quantity'] += $order_line['quantity'];

                //el cambio ya se ha aplicado la primera vez que se ha introducido el producto, lo que hacemos es, a lo que ya hay en $total_productos_con_iva sumarle el precio unitario multiplicado por la cantidad en esta veulta, que será probablemente 1
                $total_productos_con_iva += $this->webservice_order_info['order_details'][$found_key]['unit_price_tax_incl']*$order_line['quantity'];
                $total_productos_sin_iva += $this->webservice_order_info['order_details'][$found_key]['unit_price_tax_excl']*$order_line['quantity'];

            } else {
                //no está el producto
                $order_detail['external_detail_id'] = $order_line['order_line_id'];
                //enviamos vacíos los ids externos de atributo, hay que modificar webservice para que los acepte vacíos
                $order_detail['external_id_product'] = $order_line['offer_id'];
                $order_detail['external_id_product_attribute'] = "";
    
                $ids_producto = $this->getIdsProducto($order_line['offer_sku']);
    
                if ($ids_producto == false) {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR buscando ids de producto para pedido '.$this->mirakl_order_info['order_id'].' para referencia '.$order_line['offer_sku'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'.PHP_EOL, FILE_APPEND); 
    
                    $this->error = 1;
                        
                    $this->mensajes[] = '- ERROR buscando ids de producto para pedido '.$this->mirakl_order_info['order_id'].' para referencia '.$order_line['offer_sku'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'; 
                                    
                    return false;
                }
    
                $order_detail['id_product'] = $ids_producto[0];
                $order_detail['id_product_attribute'] = $ids_producto[1];
    
                $order_detail['quantity'] = $order_line['quantity'];

                //por ahora no hay customized ni message
                $order_detail['customized'] = 'false';
                $order_detail['customization_message'] = "";

                //ahora tenemos que obtener el iva del producto para el país de destino. Buscaremos el iva en Prestashop para España y sacaremos el correspondiente para el destino. Tenemos el id_country del país destino en $isos[2]
                if (!$iva_producto = $this->getProductTax($ids_producto[0], $isos[2])) {
                    return false;
                }

                $order_detail['tax_rate'] = $iva_producto;

                //ahora, dependiendo de la configuración de taxes para pedidos de la plataforma, calculamos el precio de venta del producto con y sin iva
                //en principio nosotros ignoramos lo de los gastos de envío que Mirakl reparte entre los productos, cuando hay gastos de envío, dado que el gasto final del producto es lo que cuenta para nosotros
                if ($order_tax_mode == 'TAX_INCLUDED') {
                    //el iva está incluido, calculamos el precio sin iva
                    //se ha vendido por:
                    $order_detail['unit_price_tax_incl'] = $order_line['total_price']*$this->cambio;
                    //como tiene incluido el iva, lo calculamos sin iva. pvp / (1+ iva/100) Si iva es 21, dividimos entre 1.21
                    $order_detail['unit_price_tax_excl'] = ($order_line['total_price']/(1+($iva_producto/100)))*$this->cambio;

                } else {
                    //el iva está no incluido, calculamos el precio añadiendo el iva
                    $order_detail['unit_price_tax_excl'] = $order_line['total_price']*$this->cambio;
                    //calculamos multiplicando por 1+ iva/100
                    $order_detail['unit_price_tax_incl'] = ($order_line['total_price']*(1+($iva_producto/100)))*$this->cambio;

                }

                $total_productos_con_iva += $order_detail['unit_price_tax_incl']*$order_detail['quantity'];
                $total_productos_sin_iva += $order_detail['unit_price_tax_excl']*$order_detail['quantity'];

                //el resto de campos de momento no aplican a Mirakl
                $order_detail['discount'] = 'false';
                $order_detail['reduction_type'] = "";
                $order_detail['reduction_percentage'] = "";
                $order_detail['reduction_amount'] = "";
                $order_detail['unit_price_with_reduction_tax_excl'] = "";
                $order_detail['unit_price_with_reduction_tax_incl'] = "";    
    
                //finalmente metemos el array del order_detail en order_details para webservice
                $this->webservice_order_info['order_details'][] = $order_detail;     
            }
        }        

        //tenemos preparadas las líneas de pedido, seguimos con los datos generales del pedido. Tenemso el total con y sin iva de productos en $total_productos_con_iva y $total_productos_sin_iva
        $this->webservice_order_info['order']['total_products_tax_excl'] = $total_productos_sin_iva;
        $this->webservice_order_info['order']['total_products_tax_incl'] = $total_productos_con_iva;
        //de momento mirakl sin descuentos
        $this->webservice_order_info['order']['total_discounts_tax_excl'] = 0;
        $this->webservice_order_info['order']['total_discounts_tax_incl'] = 0;

        //comprobamos si hay costes de shipping. Lo hemos configurado para que no, pero podría cambiar. Para sacarlo necesitamos saber si tax está o no incluido en lo que devuelve la api. Para el shipping sabemos que aplica el equivalente al 21% de España en el país de destino, tenemos que obtenerlo si id_country no es España id 6, es decir, si $isos[2] != 6
        if ($this->mirakl_order_info['shipping_price'] == 0) {
            $this->webservice_order_info['order']['total_shipping_tax_incl'] = 0;
            $this->webservice_order_info['order']['total_shipping_tax_excl'] = 0;
        } else {
            if ($isos[2] == 6) {
                //España, el iva es 21%
                $iva_pais = 21;

            } else {
                //obtenemos el iva equivalente al 21% para el país destino. Enviamos id_country y iva cuyo equivalente en el país a buscar, el 21 en este caso
                if (!$iva_pais = $this->getCountryTax(21, $isos[2])) {
                    return false;
                }
            }

            //tenemos el impuesto, sacamos el shipping con y sin iva dependiendo de si lleva impuestos incluidos en el pedido
            if ($order_tax_mode == 'TAX_INCLUDED') {
                $this->webservice_order_info['order']['total_shipping_tax_incl'] = $this->mirakl_order_info['shipping_price']*$this->cambio;
                //como tiene incluido el iva, lo calculamos sin iva. pvp / (1+ iva/100) Si iva es 21, dividimos entre 1.21                
                $this->webservice_order_info['order']['total_shipping_tax_excl'] = ($this->mirakl_order_info['shipping_price']/(1+($iva_pais/100)))*$this->cambio;
            } else {
                //el iva está no incluido, calculamos el precio añadiendo el iva
                $this->webservice_order_info['order']['total_shipping_tax_excl'] = $this->mirakl_order_info['shipping_price']*$this->cambio;
                //calculamos multiplicando por 1+ iva/100
                $this->webservice_order_info['order']['total_shipping_tax_incl'] = ($this->mirakl_order_info['shipping_price']*(1+($iva_pais/100)))*$this->cambio;
            }
        }

        //de momento mirakl no tiene envoltorio regalo ni mensajes, ponemos false, debe se string, no boolean porque se codifica a json
        $this->webservice_order_info['order']['gift_wrapping'] = 'false';
        $this->webservice_order_info['order']['gift_message'] = "";
        $this->webservice_order_info['order']['total_wrapping_tax_excl'] = 0;
        $this->webservice_order_info['order']['total_wrapping_tax_incl'] = 0;
        
        //para el coste total habría que sumar el coste de productos más el shipping, con y sin impuestos, dado que el total_price que sacamos directamente de la API puede incluir diferentes impuestos dependiendo de los productos que contenga el pedido. Comprobamos si las sumas coinciden con los datos recibidos, con un pequeño margen por los cálculos. Es decir, tenemos que sumar $this->webservice_order_info['total_shipping_tax_excl'] + $this->webservice_order_info['total_products_tax_excl'] y $this->webservice_order_info['total_shipping_tax_incl'] + $this->webservice_order_info['total_products_tax_incl'] y dependiendo de si el pedido lleva impuestos tendrá que coincidir con $this->mirakl_order_info['total_price']
        $total_paid_tax_excl = $this->webservice_order_info['order']['total_shipping_tax_excl'] + $this->webservice_order_info['order']['total_products_tax_excl'];                      
        $total_paid_tax_incl = $this->webservice_order_info['order']['total_shipping_tax_incl'] + $this->webservice_order_info['order']['total_products_tax_incl'];
        //ahora comparamos, dependiendo de si el impuesto va incluido
        //tenemos que multiplicar total_price de mirakl por el cambio para hacer la operación
        $error_calculos = 0;
        if ($order_tax_mode == 'TAX_INCLUDED') {            
            $diferencia = $total_paid_tax_incl - $this->mirakl_order_info['total_price']*$this->cambio;
            //si la diferencia absoluta entre ambos valores es superior a 50 centimos ¿? consideramos error, si no , metemos lo que hemos calculado nosotros, no lo que devuelve la api, aunque puede dar lugar a cierto margen de diferencia ¿?
            if (ABS($diferencia) > 0.5) {
                $error_calculos = 1;
            }             
        } else {
            $diferencia = $total_paid_tax_excl - $this->mirakl_order_info['total_price']*$this->cambio;
            //si la diferencia absoluta entre ambos valores es superior a 50 centimos ¿? consideramos error, si no , metemos lo que hemos calculado nosotros, no lo que devuelve la api, aunque puede dar lugar a cierto margen de diferencia ¿?
            if (ABS($diferencia) > 0.5) {
                $error_calculos = 1;
            }
        }

        if ($error_calculos) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR detectado en los cálculos de costes del pedido. La diferencia entre el coste total devuelto por la API y el coste calculado es superior a 0.50€ ('.ROUND($diferencia, 3).' €) para pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
                
            $this->mensajes[] = '- ERROR detectado en los cálculos de costes del pedido. La diferencia entre el coste total devuelto por la API y el coste calculado es superior a 0.50€ ('.ROUND($diferencia, 3).' €) para pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'; 
            
            return false;
        }

        $this->webservice_order_info['order']['total_paid_tax_excl'] = $total_paid_tax_excl;                      
        $this->webservice_order_info['order']['total_paid_tax_incl'] = $total_paid_tax_incl;

        //empezamos con la info de cliente y dirección de destino, que utilizaremos de momento para facturación también, pero esto quizás haya que modificarlo, lo que supondría modificar el webservice también, ya que ahora no admite las dos direcciones
        $this->webservice_order_info['customer'] = array();

        $this->webservice_order_info['customer']['external_customer_id'] = $this->mirakl_order_info['customer']['customer_id'];
        $this->webservice_order_info['customer']['email'] = $this->mirakl_order_info['customer_notification_email'];
        $this->webservice_order_info['customer']['firstname'] = $this->mirakl_order_info['customer']['firstname'];
        $this->webservice_order_info['customer']['lastname'] = $this->mirakl_order_info['customer']['lastname'];

        $this->webservice_order_info['delivery_address'] = array();

        $this->webservice_order_info['delivery_address']['firstname'] = $this->mirakl_order_info['customer']['shipping_address']['firstname'];
        $this->webservice_order_info['delivery_address']['lastname'] = $this->mirakl_order_info['customer']['shipping_address']['lastname'];

        //company, puede no venir y además puede haber dos campos en Mirakl
        if ($this->mirakl_order_info['customer']['shipping_address']['company'] || $this->mirakl_order_info['customer']['shipping_address']['company_2']) {
            if ($this->mirakl_order_info['customer']['shipping_address']['company_2']) {
                $company = $this->mirakl_order_info['customer']['shipping_address']['company'].' - '.$this->mirakl_order_info['customer']['shipping_address']['company_2'];                
            } else {
                $company = $this->mirakl_order_info['customer']['shipping_address']['company'];
            }
            //company en Prestashop tabla address no puede tener más de 64 caracteres, cortamos donde caiga si es que llega
            $company = substr($company,0,63);
        } else {
            $company = "";
        }

        $this->webservice_order_info['delivery_address']['company'] = $company; 

        $this->webservice_order_info['delivery_address']['phone'] = $this->mirakl_order_info['customer']['shipping_address']['phone_secondary'] ? $this->mirakl_order_info['customer']['shipping_address']['phone_secondary'] : "";

        $this->webservice_order_info['delivery_address']['phone_mobile'] = $this->mirakl_order_info['customer']['shipping_address']['phone'] ? $this->mirakl_order_info['customer']['shipping_address']['phone'] : "";

        $this->webservice_order_info['delivery_address']['address1'] = $this->mirakl_order_info['customer']['shipping_address']['street_1'];
        $this->webservice_order_info['delivery_address']['address2'] = $this->mirakl_order_info['customer']['shipping_address']['street_2'] ? $this->mirakl_order_info['customer']['shipping_address']['street_2'] : "";

        $this->webservice_order_info['delivery_address']['city'] = $this->mirakl_order_info['customer']['shipping_address']['city'];

        $this->webservice_order_info['delivery_address']['state'] = $this->mirakl_order_info['customer']['shipping_address']['state'] ? $this->mirakl_order_info['customer']['shipping_address']['state'] : "";

        $this->webservice_order_info['delivery_address']['postcode'] = $this->mirakl_order_info['customer']['shipping_address']['zip_code'];

        $this->webservice_order_info['delivery_address']['country_iso'] = $isos[1];

        //meto el contenido si hay de additional_info, auqnue no usaremos de momento
        $this->webservice_order_info['delivery_address']['other'] = $this->mirakl_order_info['customer']['shipping_address']['additional_info'] ? $this->mirakl_order_info['customer']['shipping_address']['additional_info'] : "";

        //a 08/07/2024 tenemos la info que usamos para webservice.
        if (!$this->webserviceAPIOrder()) {
            //no se ha podido crear.
            return false;
        }   

        return true;
    }

    //función que con el id de producto y el iso de país destino nos devuelve el iva aplicable al producto. Buscará el iva para España para el producto y su correspondencia para el país de destino si es diferente, y lo devolverá
    public function getProductTax($id_product, $id_country) {
        $sql_tax = "SELECT tax.rate
        FROM lafrips_product pro 
        JOIN lafrips_tax_rule tar ON tar.id_tax_rules_group = pro.id_tax_rules_group
        JOIN lafrips_tax tax ON tax.id_tax = tar.id_tax
        WHERE pro.id_product = $id_product
        AND tar.id_country = $id_country
        AND tax.active = 1";

        if (!$iva_producto = Db::getInstance()->getValue($sql_tax)) {            
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR buscando iva de país destino para id_product = '.$id_product.' en pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
                
            $this->mensajes[] = '- ERROR buscando iva de país destino para id_product = '.$id_product.' en pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'; 
            
            return false;
        }

        return $iva_producto;
    }

    //función que con currency iso deveulve el cambio, para lo que la moneda debe existir en frik_amazon_reglas, o será un error y deberá ser configurada. Tenemos el código ISO en $this->webservice_order_info['order']['currency_iso']. Una vez obtenido el cambio, cambiamos la iso a EUR si no lo es.
    public function getCambio() {
        $ql_cambio = "SELECT cambio FROM frik_amazon_reglas WHERE moneda = '".$this->webservice_order_info['order']['currency_iso']."'";

        if (!$this->cambio = Db::getInstance()->getValue($ql_cambio)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR buscando currency ISO '.$this->webservice_order_info['order']['currency_iso'].' de moneda para pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido. Posible falta de configuración en frik_amazon_reglas'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
                
            $this->mensajes[] = '- ERROR buscando currency ISO '.$this->webservice_order_info['order']['currency_iso'].' de moneda para pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido. Posible falta de configuración en frik_amazon_reglas'; 
            
            return false;
        }

        if ($this->webservice_order_info['order']['currency_iso'] != 'EUR') {           

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Moneda cambiada de currency ISO '.$this->webservice_order_info['order']['currency_iso'].' a EUR para pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Cambio calculado con configuración en frik_amazon_reglas'.PHP_EOL, FILE_APPEND);

            $this->webservice_order_info['order']['currency_iso'] = 'EUR';
        }

        return true;
    }

    //función que recibe un porcentaje de impuesto, nuestro iva, y el id_country de un pais, y busca el impuesto equivalente al iva de España en dicho país
    public function getCountryTax($iva, $id_country) {
        //la consulta busca el id_tax_rules_group para el porcentaje de iva (21 es el común en España) en España, id_country 6 , en subconsulta, y con ese id busca el quivalente para el $id_country del país destino
        $sql_tax = "SELECT tax.rate
        FROM lafrips_tax_rule tar 
        JOIN lafrips_tax tax ON tax.id_tax = tar.id_tax
        WHERE tar.id_country = $id_country
        AND tax.active = 1
        AND tar.id_tax_rules_group = (
            SELECT trg.id_tax_rules_group 
            FROM lafrips_tax_rule tar
            JOIN lafrips_tax_rules_group trg ON trg.id_tax_rules_group = tar.id_tax_rules_group
            JOIN lafrips_tax tax ON tax.id_tax = tar.id_tax
            WHERE tar.id_country = 6 
            AND tax.rate = $iva
            AND trg.active = 1
            AND trg.deleted = 0
        )";        

        if (!$iva_pais = Db::getInstance()->getValue($sql_tax)) {            
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR buscando iva de país destino id_country = '.$id_country.', correspondiente a iva = '.$iva.' en pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
                
            $this->mensajes[] = '- ERROR buscando iva de país destino id_country = '.$id_country.', correspondiente a iva = '.$iva.' en pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'; 
            
            return false;
        }

        return $iva_pais;
    }
    
    //función que saca información del pedido dependiendo del marketplace para averiguar el iso del país de destino y el iso de idioma. En principio en los marketplaces solo vendemos en los paises que hay definidos como España, Italia, Francia, Alemania y Portugal, pero por ejemplo Tradeinn tiene el canal ROE que incluye muchos más paises. Sacaremos el iso de donde sea y lo buscaremos en lafrips_country, donde habré insertado una columna con el iso de 3 letras, dado que algún marketplace lo lleva así. De ahí sacaremos el iso de dos letras si no lo tuvieramos y lo buscaremos en lafrips_lang. Si no estuviera lo cambiariamos por ES en idioma, para que saque el id_lang = 1 al crear el pedido en webservice. La función tiene que devolver un array con el iso de idioma y el iso de país de 2 letras
    //también devuelve el id_country del país destino
    public function getIsoCodes() {
        //04/07/2024 hay que hacer un switch con una opción por marketplace dado que varían. Pccomponentes y worten van igual
        switch ($this->marketplace) {
            case 'worten':              
            case 'pccomponentes':
                //el pedido tiene el código ISO del país, de dos letras, en customer-shipping_address-country
                $country_iso = $this->mirakl_order_info['customer']['shipping_address']['country'];
                //buscamos el iso en lafrips_lang, si no  está estableceremos language_iso como ES
                if (!Db::getInstance()->getValue('SELECT id_lang FROM lafrips_lang WHERE iso_code = "'.$country_iso.'"')) {
                    //no está en tabla idiomas, metemos ES
                    $language_iso = 'ES';
                } else {
                    //el código es de algún idioma configurado en Prestashop, devolvemos el propio código como idioma
                    $language_iso = $country_iso;
                }

                break;            
            case 'carrefour':
                //para carrefour, de momento parece que solo puede ser España
                $language_iso = 'ES';
                $country_iso = 'ES';

                break;
            case 'mediamarkt':
            case 'tradeinn':
            case 'leclerc':
                //el pedido tiene el código ISO del país, de tres letras, en customer-shipping_address-country_iso_code. Tenemos que buscar en la tabla country para encontrar la correspondencia de 2 letras, esa columna la habré creado yo.
                $iso_3 = $this->mirakl_order_info['customer']['shipping_address']['country_iso_code'];
                //buscamos el código de 3 letras en la columna que he insertado. Los únicos que no están son Antillas neerlandesas y ESC y ESM, canarias y Ceuta/melilla
                $country_iso = Db::getInstance()->getValue('SELECT iso_code FROM lafrips_country WHERE iso_code_3 = "'.$iso_3.'"');

                //buscamos el iso en lafrips_lang, si no  está estableceremos language_iso como ES
                if (!Db::getInstance()->getValue('SELECT id_lang FROM lafrips_lang WHERE iso_code = "'.$country_iso.'"')) {
                    //no está en tabla idiomas, metemos ES
                    $language_iso = 'ES';
                } else {
                    //el código es de algún idioma configurado en Prestashop, devolvemos el propio código como idioma
                    $language_iso = $country_iso;
                }

                break;
            default:
                //puede ser que aún no hemos configurado esta parte del marketplace por ser nuevo, error e interrumpimos
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR buscando ISOs de idioma y país para pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Posible falta de configuración en SWITCH función getIsoCodes()'.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                    
                $this->mensajes[] = '- ERROR buscando ISOs de idioma y país para pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Posible falta de configuración en SWITCH función getIsoCodes()'; 
                
                return false;
            }

            //ya tenemos $country_iso, sacamos id_country
            if (!$id_country = Db::getInstance()->getValue('SELECT id_country FROM lafrips_country WHERE iso_code = "'.$country_iso.'"')) {
                //puede ser que aún no hemos configurado esta parte del marketplace por ser nuevo, error e interrumpimos
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR buscando id_country de país destino para pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                    
                $this->mensajes[] = '- ERROR buscando id_country de país destino para pedido '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).' - Interrumpida creación de pedido'; 
                
                return false;
            }

          return array($language_iso, $country_iso, $id_country);
    }

    //función que hace la llamada a la API del webservice de Prestashop para crear el pedido en Prestashop. Tenemos la info en $this->webservice_order_info y las credenciales del webservice de Prestashop en $this->webservice_credentials
    public function webserviceAPIOrder() {
        echo '<br><br>';
        echo '<pre>';
        print_r($this->webservice_order_info);
        echo '</pre>';

        echo '<br>json<br>';
        echo '<pre>';
        print_r(json_encode($this->webservice_order_info) );
        echo '</pre>';

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Preparando llamada a API Webservice para creación de pedido origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].', order_data json:'.PHP_EOL, FILE_APPEND); 
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.json_encode($this->webservice_order_info).PHP_EOL, FILE_APPEND);          

        $this->prestashop_order_info = array();

        //preparamos POSTFIELDS
        $parameters = array(
            "user" => $this->webservice_credentials['user'],
            "user_pwd" => $this->webservice_credentials['user_pwd'],
            "function" => "add_order",
            "order_data" => json_encode($this->webservice_order_info) 
        );
        
        $postfields = http_build_query($parameters);

        // if ($this->test) {
        //     $endpoint = "https://lafrikileria.com/test/api/order?output_format=JSON";
        // } else {
        //     $endpoint = "https://lafrikileria.com/api/order?output_format=JSON";
        // }       

        //22/07/2024 como cada vez que hago pruebas en test me olvido de poner $this->test a true y se generan los pedidos de prueba en producción, vamos a indicar el endpoint correcto así:
        if (strpos(_PS_ROOT_DIR_, "/test") !== false) {
            $endpoint = "https://lafrikileria.com/test/api/order?output_format=JSON";
        } else {
            $endpoint = "https://lafrikileria.com/api/order?output_format=JSON";
        }

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postfields,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic '.base64_encode($this->webservice_credentials['webservice_pwd'])           
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

            $error_message = 'ERROR API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        if ($response) { 
            
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            //pasamos el JSON de respuesta a un array PHP. 
            $response_decode = json_decode($response, true);        
            
            // echo '<br><br>';
            // echo '<pre>';
            // print_r($response_decode);
            // echo '</pre>';

            if ($http_code != 200) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' NO ES CORRECTA'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' NO ES CORRECTA'; 
                $this->mensajes[] = ' - order_data json: '.json_encode($this->webservice_order_info); 
                $this->mensajes[] = ' - Http Response Code = '.$http_code;

                return false;
            }

            if ($response_decode['success'] == false) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' no tuvo éxito'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Response Code = '.$response_decode['code'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - response json = '.$response.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].' no tuvo éxito'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Response Code = '.$response_decode['code'];
                $this->mensajes[] = ' - order_data json: '.json_encode($this->webservice_order_info); 
                $this->mensajes[] = ' - response json = '.$response;

                if ($response_decode['messages']) {
                    foreach ($response_decode['messages'] AS $message) {
                        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Message: '.$message.PHP_EOL, FILE_APPEND); 

                        $this->mensajes[] = ' - Message: '.$message;
                    }                    
                } else {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Mensaje error no definido'.PHP_EOL, FILE_APPEND); 

                    $this->mensajes[] = ' - Mensaje error no definido';                    
                }

                return false;
            }

            if ($response_decode['success'] == true) {
                //la creación del pedido ha sido un éxito, recogemos la información devuelta necesaria para actualizar lafrips_mirakl_orders
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta CORRECTA de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Response Code = '.$response_decode['code'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ID_ORDER = '.$response_decode['data']['frikileria_order_id'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ID_WEBSERVICE_ORDER = '.$response_decode['data']['id_webservice_order'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ID_CUSTOMER = '.$response_decode['data']['frikileria_customer_id'].PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ID_ADDRESS = '.$response_decode['data']['frikileria_address_delivery_id'].PHP_EOL, FILE_APPEND);


                $this->prestashop_order_info['id_order'] = $response_decode['data']['frikileria_order_id'];
                $this->prestashop_order_info['id_webservice_order'] = $response_decode['data']['id_webservice_order'];
                $this->prestashop_order_info['id_customer'] = $response_decode['data']['frikileria_customer_id'];
                $this->prestashop_order_info['id_address_delivery'] = $response_decode['data']['frikileria_address_delivery_id'];

                return true;   
            }  

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, respuesta sin response de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id'].PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API Webservice Prestashop para petición add_order de origen '.ucfirst($this->webservice_order_info['origin']).' y order_id '.$this->webservice_order_info['order']['external_order_id']; 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }
    }

    public function getWebserviceCredentials() {
        //Obtenemos la key leyendo el archivo mirakl_prestashop_webservice_credentials.json donde hemos almacenado user, user_pwd y webservice_pwd
        $secrets_json = file_get_contents(dirname(__FILE__).'/../secrets/mirakl_prestashop_webservice_credentials.json');

        if ($secrets_json == false) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR obteniendo credenciales para el webservice de Prestashop, abortando proceso'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes[] = ' - ERROR obteniendo credenciales para el webservice de Prestashop, abortando proceso'; 

            return false;
        }        
        
        //almacenamos decodificado como array asociativo (segundo parámetro true, si no sería un objeto)
        $this->webservice_credentials = json_decode($secrets_json, true); 

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Credenciales de Webservice obtenidas correctamente'.PHP_EOL, FILE_APPEND); 

        return true;        
    }

    //función que busca pedidos de Mirakl pendientes de aceptar, comprueba stock y acepta líneas de pedido si es viable
    public function aceptarPedidos() {
        //obtenemos la info vía API
        if (!$this->getInfoAPIOR11('lista')) {
            //problemas recibiendo la info, pasamos al siguiente
            return;
        }

        //tenemos la lista de pedidos pendientes de aceptar en $this->respuesta_OR11. Lo recorremos, comprobando los productos y si están disponibles aceptaremos las líneas de pedido, lo que da el pedido por aceptado en Mirakl. Insertaremos los datos básicos en lafrips_mirakl_orders, asegurándonos de que no estén ya insertados. Si un pedido no es viable, lo insertaremos como cancelado en la tabla
        foreach ($this->respuesta_OR11['orders'] AS $mirakl_order) {
           
            $this->mirakl_order_info = array();

            $this->mirakl_order_info['order_id'] = $mirakl_order['order_id'];
            $this->mirakl_order_info['channel'] = $mirakl_order['channel']['code'] ? $mirakl_order['channel']['code'] : "INIT";

            //recorremos los productos del pedido. Se dan casos en los que un cliente compra dos unidades del mismo producto y se crean dos lineas de pedido de una unidad cada uno, de modo que por si acaso vamos a asegurarnos de agrupar todo para luego comprobar la disponibilidad del producto.
            $productos_pedido = array();
            $lineas_aceptar = array("order_lines" => array());
            foreach ($mirakl_order['order_lines'] AS $order_line) {
                //vamos preparando el array de lineas de pedido para aceptar posteriormente si todo es correcto y no tener que volver a recorrer order_lines
                $lineas_aceptar["order_lines"][] = array("accepted" => true, "id" => $order_line['order_line_id']);

                //comprobamos si la sku existe como key en el array de productos que estamos generando, si no se mete la nueva, si si, se suma la ccantidad
                if ((array_key_exists($order_line['offer_sku'], $productos_pedido))) {
                    $productos_pedido[$order_line['offer_sku']]['quantity'] = $productos_pedido[$order_line['offer_sku']]['quantity'] + $order_line['quantity'];
                } else {
                    $productos_pedido[$order_line['offer_sku']]['order_line_id'] = $order_line['order_line_id'];
                    $productos_pedido[$order_line['offer_sku']]['quantity'] = $order_line['quantity'];
                }                    
            }

            //ahora recorremos el array de productos para comprobar su disponibilidad
            $error_stock = 0;
            $productos_sin_stock = array();
            foreach ($productos_pedido AS $key => $value) {
                $referencia_prestashop = $key;
                $quantity = $value['quantity'];

                if (!$this->productoDisponible($referencia_prestashop, $quantity)) {
                    //el producto no tiene stock suficiente, o no se encuentra en Prestashop, o la referencia tiene algún error. Avisamos y ¿cancelamos en Mirakl?
                    //Por ahora, enviamos email de aviso y pasamos del pedido
                    $error_stock = 1;

                    $productos_sin_stock[] = $referencia_prestashop;                    
                } 
            }           

            //comprobamos si ha habido algún problema de stock, si no lo ha habido, aceptamos las líneas de pedido. Para ello hemos preparado antes el array $lineas_aceptar que codificamos a json para enviar con la API OR21
            if (!$error_stock) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido order_id = '.$this->mirakl_order_info['order_id'].' para marketplace '.ucfirst($this->marketplace).' DISPONE DE STOCK SUFICIENTE para el pedido'.PHP_EOL, FILE_APPEND);  

                if ($this->acceptOrderLinesAPIOR21($lineas_aceptar)) {
                    //insertamos datos en lafrips_mirakl_orders
                    $this->insertMiraklOrder();

                } 
            } else {
                //enviamos email de aviso
                $this->enviaEmail($productos_sin_stock);
            }

            continue;
        }

        return;
    }

    //función para revisar pago correcto y estado SHIPPING de pedidos recién aceptados, aún no en Prestashop. De confirmarse se marcarán como revisado_shipping = 1 y se crearán posteriormente
    public function confirmarPedidosAceptados() {
        if (!$this->getPedidos('confirmado shipping')) {
            return;
        }

        //tenemos los pedidos a revisar en $this->pedidos_revisar, solo tenemos su order_id de Mirakl. Utilizamos la API OR11 para obtener la información de cada pedido y sacar su order_state para comprobar si es SHIPPING y marcarlo como revisado_shipping o en caso contrario comprobar fecha de aceptación y si es superior a x minutos lanzar una alerta. Estos pedidos aún no existen en Prestashop
        foreach ($this->pedidos_revisar AS $pedido) {                  

            $this->order_id = $pedido['order_id'];

            //obtenemos la info vía API
            if (!$this->getInfoAPIOR11('unico')) {
                //problemas recibiendo la info, marcamos procesando = 0 a pedido y pasamos al siguiente
                $this->setProcesando($pedido['id_mirakl_orders'], false);

                continue;
            }

            //tenemos la info del pedido en el array $this->respuesta_OR11. Es un array con subarray 'orders' y al ser un solo pedido contiene su info en $this->respuesta_OR11['orders'][0]
            //si el estado de pedido es SHIPPING lo damos por confirmado en lafrips_mirakl_orders y enviamos a crear, si no, comprobamos tiempo pasado desde su aceptación por nuestro lado y avisamos si esmayor a x minutos
            if ($this->respuesta_OR11['orders'][0]['order_state'] == 'SHIPPING') { 
                //por si hay algún error al crear el pedido, lo marcamos revisado shipping ahora
                $this->updateMiraklOrders('revisado');

                //para facilitar el código, metemos la info del pedido directamente en $this->mirakl_order_info   
                $this->mirakl_order_info = $this->respuesta_OR11['orders'][0];    

                if (!$this->crearPedido()) {
                    $this->setProcesando($pedido['id_mirakl_orders'], false);

                    continue;
                }   
                
                //se ha creado el pedido y actualizamos la tabla mirakl_orders (también procesando = 0)
                $this->updateMiraklOrders('creado');
                
                continue;
                
            } else {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido order_id = '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' NO SE ENCUENTRA TODAVIA en estado SHIPPING'.PHP_EOL, FILE_APPEND);      

                //comprobamos el tiempo que lleva en espera y avisamos si procede enviando el estado actual del pedido
                //la fecha de entrada y creación del pedido está en $this->respuesta_OR11['orders'][0]['created_date']
                $created_date = $this->respuesta_OR11['orders'][0]['created_date'];

                //la fecha de aceptación del pedido está en $this->respuesta_OR11['orders'][0]['acceptance_decision_date']
                $acceptance_date = $this->respuesta_OR11['orders'][0]['acceptance_decision_date'];

                //la hora es de formato 2024-07-01T10:45:01Z, es UTC (nosotros estamos en Madrid/Europe que sería +02:00). La comparamos con now en UTC y sacamos las horas. Cuando sean más de x avisamos.
                $horas_diferencia_creacion = $this->timeDifference($created_date);

                $horas_diferencia_aceptacion = $this->timeDifference($acceptance_date);                

                //si las horas de diferencia desde aceptación de pedido son más de 12 avisamos, sino pasamos a otro
                if ($horas_diferencia_aceptacion > 12) {
                    file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Warning, el pedido order_id = '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' creado hace '.$horas_diferencia_creacion.' horas, lleva '.$horas_diferencia_aceptacion.' horas desde su aceptación pero no se encuentra en estado SHIPPING'.PHP_EOL, FILE_APPEND);                     
            
                    $this->error = 1;
                
                    $this->mensajes[] = ' - Warning, el pedido order_id = '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' creado hace '.$horas_diferencia_creacion.' horas, lleva '.$horas_diferencia_aceptacion.' horas desde su aceptación pero no se encuentra en estado SHIPPING';   
                     
                } 

                //quitamos procesando
                $this->setProcesando($pedido['id_mirakl_orders'], false);                    
                    
                continue;    
            }            
        }

        return;
    }

    //función que recibe un date time y devuelve la diferencia en horas con now. En principio comparamos now en utc  con ese date time
    public function timeDifference($date_time) {        
        //la hora que devuelve la api es de formato 2024-07-01T10:45:01Z, es UTC. Creamos un objeto datetime
        $date = new DateTime($date_time, new DateTimeZone('UTC'));

        // sacamos fecha y hora actual en UTC        
        $current_date_time = new DateTime('now', new DateTimeZone('UTC'));        

        // calculamos la diferencia
        $date_diferencia = $current_date_time->diff($date);

        //pasamos la diferencia a horas y lo devolvemos
        return ($date_diferencia->days * 24) + $date_diferencia->h + ($date_diferencia->i / 60) + ($date_diferencia->s / 3600);;
    }

    //función para revisar envio de pedidos aceptados y procesados en Prestashop.
    public function confirmarShipping() {
        if (!$this->getPedidos('enviado prestashop')) {
            return;
        }

        $id_estado_enviado = Configuration::get('PS_OS_SHIPPING');

        //tenemos los pedidos a revisar en $this->pedidos_revisar. Comprobamos el estado de pedido en Prestashop, y si está enviado procedemos a recoger la info para enviar vía API OR23 los datos de transporte y darlo por enviado en Mirakl vía API OR24
        foreach ($this->pedidos_revisar AS $pedido) {
            $this->order_id = null;
            $this->id_order = null;

            if ($pedido['current_state'] == $id_estado_enviado) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido '.$pedido['id_order'].' - '.$pedido['order_id'].' Enviado. Procesamos'.PHP_EOL, FILE_APPEND); 

                $this->order_id = $pedido['order_id'];
                $this->id_order = $pedido['id_order'];

                //sacamosla información de transporte 
                if (!$this->getShippingInfo()) {
                    //problemas obteniendo la info, pasamos al siguiente
                    //quitamos procesando
                    $this->setProcesando($pedido['id_mirakl_orders'], false); 

                    continue;
                }

                //enviamos la info vía API
                if (!$this->updateCarrierInfoAPIOR23()) {
                    //problemas enviando la info, pasamos al siguiente
                    //quitamos procesando
                    $this->setProcesando($pedido['id_mirakl_orders'], false); 

                    continue;
                }

                //actualizamos la tabla mirakl_orders
                $this->updateMiraklOrders('enviado');

                continue;

            } else {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido '.$pedido['id_order'].' - '.$pedido['order_id'].' No Enviado'.PHP_EOL, FILE_APPEND); 

                //quitamos procesando
                $this->setProcesando($pedido['id_mirakl_orders'], false); 

                continue;
            }
        }

        return;        
    }

    //función para revisar pedidos  procesados y enviados en Prestashop que ya tienen los datos de shipping actualizados en Mirakl y enviar confirmación de envío a Mirakl
    public function confirmarEnvios() {
        if (!$this->getPedidos('confirmado envio')) {
            return;
        }        

        //tenemos los pedidos a revisar en $this->pedidos_revisar. Procedemos a darlo por enviado en Mirakl vía API OR24
        foreach ($this->pedidos_revisar AS $pedido) {
            $this->order_id = null;
            $this->id_order = null;
            
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido '.$pedido['id_order'].' - '.$pedido['order_id'].' Datos shipping enviados. Procesamos confirmación'.PHP_EOL, FILE_APPEND); 

            $this->order_id = $pedido['order_id'];
            $this->id_order = $pedido['id_order'];
            
            //damos el pedido por envíado vía API
            if (!$this->validateShipmentAPIOR24()) {
                //problemas validando, pasamos al siguiente
                //quitamos procesando
                $this->setProcesando($pedido['id_mirakl_orders'], false); 

                continue;
            }

            //actualizamos la tabla mirakl_orders
            $this->updateMiraklOrders('confirmado');

            continue;
        }

        return;        
    }

    //función que obtiene los datos de transporte de un pedido en estado enviado de Prestashop
    public function getShippingInfo() {
        $this->shipping_info = array();

        //primero obtenemos el id_carrier del pedido de la tabla order_carrier
        $order_carrier = Db::getInstance()->getRow("SELECT id_carrier, tracking_number FROM lafrips_order_carrier WHERE id_order = ".$this->id_order);

        $id_carrier = $order_carrier['id_carrier'];
        $this->shipping_info['tracking_number'] = $order_carrier['tracking_number'];

        //con id_carrier vamos a lafrips_carrier y obtenemos su id_reference para sacar el transportista
        $id_reference = Db::getInstance()->getValue("SELECT id_reference FROM lafrips_carrier WHERE id_carrier = $id_carrier");

        //con id_reference podemos ir a lafrips_mirakl_carriers y sacar el código de transportista que nos hacen falta del transportista para Mirakl
        $mirakl_carrier = Db::getInstance()->getRow("SELECT * FROM lafrips_mirakl_carriers WHERE id_reference = $id_reference");

        $this->shipping_info['carrier_standard_code'] = $mirakl_carrier['carrier_standard_code'];

        //comprobamos que tenemos datos
        if (!$this->shipping_info['carrier_standard_code'] || !$this->shipping_info['tracking_number']) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR, no se pudo obtener la información completa de transporte para pedido Enviado id_order = '.$this->id_order.'. id_carrier = '.$id_carrier.', id_reference = '.$id_reference.', tracking_number = '.$this->shipping_info['tracking_number'].', carrier_standard_code = '.$this->shipping_info['carrier_standard_code'].PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido id_order = '.$this->id_order.', Mirakl order_id = '.$this->order_id.' - SHIPPING NO VALIDADO'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - ERROR, no se pudo obtener la información completa de transporte para pedido Enviado id_order = '.$this->id_order.'. id_carrier = '.$id_carrier.', id_reference = '.$id_reference.', tracking_number = '.$this->shipping_info['tracking_number'].', carrier_standard_code = '.$this->shipping_info['carrier_standard_code'];
            $this->mensajes[] = ' - Pedido id_order = '.$this->id_order.', Mirakl order_id = '.$this->order_id.' - SHIPPING NO VALIDADO';

            return false;
        } 

        return true;
    }

    //función que gestiona el proceso de generar la factura de pedidos ya confirmados en Mirakl, descargando dicha factura del pedido en Prestashop, a nuestro servidor y después llama a la API OR74 para subirla al marketplace de Mirakl correspondiente
    public function gestionarFactura() {
        if (!$this->getPedidos('factura')) {
            return;
        }        

        //tenemos los pedidos a revisar en $this->pedidos_revisar. 
        foreach ($this->pedidos_revisar AS $pedido) {
            $this->order_id = null;
            $this->id_order = null;
            
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Pedido '.$pedido['id_order'].' - '.$pedido['order_id'].' finalizado, procesamos factura'.PHP_EOL, FILE_APPEND); 

            $this->order_id = $pedido['order_id'];
            $this->id_order = $pedido['id_order'];

            if (!$this->generaFactura()) {
                //problemas generando o almacenando la factura del pedido
                //quitamos procesando
                $this->setProcesando($pedido['id_mirakl_orders'], false); 

                continue;
            }
            
            //damos el pedido por envíado vía API. Tenemos la ruta completa de la factura en $this->invoice_path.$this->invoice_name
            if (!$this->subirFacturaAPIOR74()) {
                //problemas exportando el archivo, pasamos al siguiente
                //quitamos procesando
                $this->setProcesando($pedido['id_mirakl_orders'], false); 

                continue;
            }

            //actualizamos la tabla mirakl_orders
            $this->updateMiraklOrders('factura');

            continue;
        }

        return;        
    }

    //función que busca un pedido concreto de Mirakl para recoger su información (para comprobar si está confirmado en Mirakl o recoger su info para crearlo en Prestashop) o busca todos los pedidos en un estado concreto en Mirakl para ser aceptados
    public function getInfoAPIOR11($request) {
        $this->respuesta_OR11 = null;

        if ($request == 'unico') {
            //pedidmos información específica de un pedido para comprobar su estado en Mirakl
            $url = "?order_ids=".$this->order_id;

            $request = ' info pedido '.$this->order_id;

        } elseif ($request == 'lista') {
            //pedimos lista de pedidos de Mirakl en un estado concreto (WAITING_ACCEPTANCE)
            $url = "?order_state_codes=WAITING_ACCEPTANCE";

            $request = ' lista pedidos WAITING_ACCEPTANCE ';
        }

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/orders'.$url,
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

            $error_message = 'ERROR API Mirakl OR11 /api/orders para petición de '.strtoupper($request).' para marketplace '.ucfirst($this->marketplace).' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        if ($response) { 
            
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            //pasamos el JSON de respuesta a un array PHP. 
            $response_decode = json_decode($response, true);          

            if (($http_code < 200) || ($http_code > 299)) {
                if ($response_decode['message']) {
                    $mensaje_error = $response_decode['message'];
                } else {
                    $mensaje_error = "Mensaje error no definido";
                }

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Mirakl OR11 /api/orders para petición de '.strtoupper($request).' para marketplace '.ucfirst($this->marketplace).' NO ES CORRECTA'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API Mirakl OR11 /api/orders para petición de '.strtoupper($request).' para marketplace '.ucfirst($this->marketplace).' NO ES CORRECTA'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Message: '.$mensaje_error;                

                return false;
            }

            //si la llamada es correcta , response sería el json sin más, nos aseguramos de que contiene algún pedido buscando total_count
            if ($response_decode['total_count'] > 0) {
                $this->respuesta_OR11 = $response_decode;

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API Mirakl OR11 /api/orders para petición de '.strtoupper($request).' para marketplace '.ucfirst($this->marketplace).' CORRECTA'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Recibida información de total_count = '.$response_decode['total_count'].' pedido/s'.PHP_EOL, FILE_APPEND);                                    

                return true;  
            } else {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, respuesta de la API Mirakl OR11 /api/orders para petición de '.strtoupper($request).' para marketplace '.ucfirst($this->marketplace).' CORRECTA pero NO DEVOLVIÓ INFORMACIÓN DE NINGÚN PEDIDO'.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Recibida información de total_count = '.$response_decode['total_count'].' pedido/s'.PHP_EOL, FILE_APPEND);  

                // $this->error = 1;
                    
                $this->mensajes[] = 'Atención, respuesta de la API Mirakl OR11 /api/orders para petición de '.strtoupper($request).' para marketplace '.ucfirst($this->marketplace).' CORRECTA pero NO DEVOLVIÓ INFORMACIÓN DE NINGÚN PEDIDO'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code; 
                $this->mensajes[] = 'Recibida información de total_count = '.$response_decode['total_count'].' pedido/s';                           

                return false;
            }
                                             

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, respuesta sin response de la API Mirakl OR11 /api/orders para petición de '.strtoupper($request).' para marketplace '.ucfirst($this->marketplace).PHP_EOL, FILE_APPEND); 

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API Mirakl OR11 /api/orders para petición de '.strtoupper($request).' para marketplace '.ucfirst($this->marketplace); 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }

    }

    //función que acepta o rechaza líneas de pedido
    public function acceptOrderLinesAPIOR21($order_lines) {
        //preparamos el json para las líneas. "accepted" e "id" por línea
        $json_order_lines = json_encode($order_lines);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/orders/'.$this->mirakl_order_info['order_id'].'/accept',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $json_order_lines,
            CURLOPT_HTTPHEADER => array(
                'Authorization: '.$this->shop_key,                
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

            $error_message = 'ERROR API Mirakl OR21 para petición api/orders/:order_id/accept para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->mirakl_order_info['order_id'].' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }

        //esta API no tiene response, solo podemos comporbar el código http, que será 204 si es correcto
        $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

        curl_close($curl);                    

        //si la API es correcta no devuelve nada y el código http es 204
        if ($http_code != 204) {
            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response);     

            if ($response_decode->message) {
                $mensaje_error = $response_decode->message;
            } else {
                $mensaje_error = "Mensaje error no definido";
            }

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Mirakl OR21 para petición api/orders/:order_id/accept para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->mirakl_order_info['order_id'].' NO ES CORRECTA'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes[] = 'Atención, la respuesta de la API Mirakl OR21 para petición api/orders/:order_id/accept para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->mirakl_order_info['order_id'].' NO ES CORRECTA'; 
            $this->mensajes[] = 'Http Response Code = '.$http_code;
            $this->mensajes[] = 'API Message: '.$mensaje_error;                

            return false;
        }

        //la llamada es correcta 

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Mirakl OR21 para petición api/orders/:order_id/accept para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->mirakl_order_info['order_id'].' CORRECTA, LINEAS DE PEDIDO ACEPTADAS'.PHP_EOL, FILE_APPEND);                      

        return true;         
    }

    //función para enviar los datos de envío de un pedido enviado en Prestashop a Mirakl
    public function updateCarrierInfoAPIOR23() {
        //preparamos el json para la info de shipping. Solo vamos a enviar el código standard de transportista y el número de tracking
        $json_shipping_info = json_encode($this->shipping_info);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/orders/'.$this->order_id.'/tracking',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $json_shipping_info,
            CURLOPT_HTTPHEADER => array(
                'Authorization: '.$this->shop_key,                
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

            $error_message = 'ERROR API Mirakl OR23 para petición api/orders/:order_id/tracking para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->order_id.', Prestashop id_order = '.$this->id_order.' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        //esta API no devuelve nada si la respuesta es correcta, comprobamos el código http 
        $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

        curl_close($curl);                    

        //si la API es correcta no devuelve nada y el código http es 204
        if ($http_code != 204) {
            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response);     

            if ($response_decode->message) {
                $mensaje_error = $response_decode->message;
            } else {
                $mensaje_error = "Mensaje error no definido";
            }

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Mirakl OR23 para petición api/orders/:order_id/tracking para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->order_id.', Prestashop id_order = '.$this->id_order.' NO ES CORRECTA'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes[] = 'Atención, la respuesta de la API Mirakl OR23 para petición api/orders/:order_id/tracking para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->order_id.', Prestashop id_order = '.$this->id_order.' NO ES CORRECTA'; 
            $this->mensajes[] = 'Http Response Code = '.$http_code;
            $this->mensajes[] = 'API Message: '.$mensaje_error;                

            return false;
        }

        //la llamada es correcta 

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Mirakl OR23 para petición api/orders/:order_id/tracking para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->order_id.', Prestashop id_order = '.$this->id_order.' CORRECTA, shipping info actualizada'.PHP_EOL, FILE_APPEND);           
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - carrier_standard_code = '.$this->shipping_info['carrier_standard_code'].PHP_EOL, FILE_APPEND);  
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - tracking_number = '.$this->shipping_info['tracking_number'].PHP_EOL, FILE_APPEND);            
        
        return true;  
    }

    //función que actualiza como enviado el pedido en Mirakl, que deberá pasar a estado SHIPPED y deberá tener sus datos de shipping actualizados
    public function validateShipmentAPIOR24() {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/orders/'.$this->order_id.'/ship',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',            
            CURLOPT_HTTPHEADER => array(
                'Authorization: '.$this->shop_key,
                'Content-Length: 0' //hay que añadir este parámetro o devuelve error 411, que indica Length required
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

            $error_message = 'ERROR API Mirakl OR24 para petición api/orders/:order_id/ship para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->order_id.', Prestashop id_order = '.$this->id_order.' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - '.$error_message.PHP_EOL, FILE_APPEND);   

            $this->error = 1;
            
            $this->mensajes[] = ' - '.$error_message;            
            
            return false;            
        }
        
        $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

        curl_close($curl);                    

        //si la API es correcta no devuelve nada y el código http es 204
        if ($http_code != 204) {
            //pasamos el JSON de respuesta a un objeto PHP. 
            $response_decode = json_decode($response);     

            if ($response_decode->message) {
                $mensaje_error = $response_decode->message;
            } else {
                $mensaje_error = "Mensaje error no definido";
            }

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API Mirakl OR24 para petición api/orders/:order_id/ship para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->order_id.', Prestashop id_order = '.$this->id_order.' NO ES CORRECTA'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes[] = 'Atención, la respuesta de la API Mirakl OR24 para petición api/orders/:order_id/ship para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->order_id.', Prestashop id_order = '.$this->id_order.' NO ES CORRECTA'; 
            $this->mensajes[] = 'Http Response Code = '.$http_code;
            $this->mensajes[] = 'API Message: '.$mensaje_error;                

            return false;
        }

        //la llamada es correcta 

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Mirakl OR24 para petición api/orders/:order_id/ship para marketplace '.ucfirst($this->marketplace).' Mirakl order_id = '.$this->order_id.', Prestashop id_order = '.$this->id_order.' CORRECTA, envío CONFIRMADO en Mirakl'.PHP_EOL, FILE_APPEND);                      

        return true; 
    }

    //función que genera y almacena la factura de un pedido que existe en Prestashop. Después se llamará a la API OR74 para subirla a su marketplace. Necesitamos id_order, order_id y ruta del servidor
    //utilizamos la clase PDF de Prestashop para generar la factura con el template TEMPLATE_INVOICE
    public function generaFactura() {
        //instanciamos el pedido de Prestashop
        $order = new Order((int)$this->id_order);

        if (!Validate::isLoadedObject($order)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR instanciando pedido '.$this->id_order.' con order_id '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' para generar factura'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - ERROR instanciando pedido '.$this->id_order.' con order_id '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' para generar factura';

            return false;
        }
        
        // Hook::exec('actionPDFInvoiceRender', array('order_invoice_list' => $order_invoice_list));      

        //llamamos a clase PDF de Prestashop con la info que necesita para generar la factura
        $pdf = new PDF($order->getInvoicesCollection(), PDF::TEMPLATE_INVOICE, Context::getContext()->smarty);        

        //render(false) porque indica display false
        $pdf_content = $pdf->render(false); 

        //generamos el nombre que queremos  dar a la factura, con la ruta de la carpeta en el servidor (miraklfrik/documents)
        $this->invoice_name = 'FACT_'.$this->id_order.'_'.$this->order_id.'_'.$this->marketplace.'.pdf';
        
        //guardamos la factura. file_put_contents() devuelve false si falla, y el número de bytes del archivo si es correcto, pongo error si menos de 2 bytes ¿?.
        if ((file_put_contents($this->invoice_path.$this->invoice_name, $pdf_content) == false) || (file_put_contents($this->invoice_path.$this->invoice_name, $pdf_content) < 2)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR generando y/o almacenando factura para pedido '.$this->id_order.' con order_id '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' a ruta '.$this->invoice_path.$this->invoice_name.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - ERROR generando y/o almacenando factura para pedido '.$this->id_order.' con order_id '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' a ruta '.$this->invoice_path.$this->invoice_name;

            return false;
        }

        return true;
    }

    //función que con el order_id y la ruta de la factura la envía al marketplace utilizando la API OR74. Tenemso la ruta completa de la factura en $this->invoice_path.$this->invoice_name
    public function subirFacturaAPIOR74() {
        //la API necesita el order_id del pedido, el archivo (su ruta a la factura) y un texto en formato html ¿? llamado order_documents con este formato según el ejemplo de postman:
        /*
            'order_documents' => '<body>
                <order_documents>
                    <order_document>
                        <file_name>filename1.txt</file_name>
                        <type_code>CUSTOMER_INVOICE</type_code>
                    </order_document>
                    <order_document>
                        <file_name>return_label.pdf</file_name>
                        <type_code>SYSTEM_RETURN_LABEL</type_code>
                        <entity>
                            <id>c0703388-df01-41c8-8a03-bc797c93515f</id>
                            <type>RETURN</type>
                        </entity>
                    </order_document>
                </order_documents>
            </body>
            '
        */
        //cojo la parte de CUSTOMER_INVOICE a ver si sirve
        $order_documents = '<body>
                                <order_documents>
                                    <order_document>
                                        <file_name>'.$this->invoice_name.'</file_name>
                                        <type_code>CUSTOMER_INVOICE</type_code>
                                    </order_document>
                                </order_documents>
                            </body>';

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->end_point.'/api/orders/'.$this->order_id.'/documents',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array(
                'files' => new CURLFILE($this->invoice_path.$this->invoice_name),
                'order_documents' => $order_documents
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

            $error_message = 'ERROR API Mirakl para petición api/orders/:order_id/documents para marketplace '.ucfirst($this->marketplace).' - Excepción:'.$exception.' - Exception thrown in '.$file.' on line '.$line.': [Code '.$code.']';

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

            //la respuesta correcta es code 200 aunque no estoy seguro dado que 
            if ($http_code != 200) {
                if ($response_decode->message) {
                    $mensaje_error = $response_decode->message;
                } else {
                    $mensaje_error = "Mensaje error no definido";
                }

                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, la respuesta de la API a petición api/orders/:order_id/documents para factura de pedido '.$this->id_order.' de order_id '.$this->order_id.' para marketplace '.ucfirst($this->marketplace).' NO ES CORRECTA'.PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND); 
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - API Message: '.$mensaje_error.PHP_EOL, FILE_APPEND); 

                $this->error = 1;
                
                $this->mensajes[] = 'Atención, la respuesta de la API a Atención, la respuesta de la API a petición api/orders/:order_id/documents para factura de pedido '.$this->id_order.' de order_id '.$this->order_id.' para marketplace '.ucfirst($this->marketplace).' NO ES CORRECTA'; 
                $this->mensajes[] = 'Http Response Code = '.$http_code;
                $this->mensajes[] = 'API Message: '.$mensaje_error;                

                return false;
            }

            //parece que puede que con http code 200 devuelva algún error
            if ($response_decode->errors_count != 0) {
                //no sé si ha funcionado o no, marcaremos error para avisar y guardamos json de response
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - POSIBLE ERROR generando factura de pedido '.$this->id_order.' de order_id '.$this->order_id.' para marketplace '.ucfirst($this->marketplace).PHP_EOL, FILE_APPEND);
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - response json: '.$response.PHP_EOL, FILE_APPEND);

                $this->error = 1;
                    
                $this->mensajes[] = 'POSIBLE ERROR generando factura de pedido '.$this->id_order.' de order_id '.$this->order_id.' para marketplace '.ucfirst($this->marketplace); 
                $this->mensajes[] = ' - response json: '.$response;
            }

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Respuesta de la API a petición api/orders/:order_id/documents para factura de pedido '.$this->id_order.' de order_id '.$this->order_id.' para marketplace '.ucfirst($this->marketplace).' CORRECTA'.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Http Response Code = '.$http_code.PHP_EOL, FILE_APPEND);
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Factura subida'.PHP_EOL, FILE_APPEND);

            return true;            

        } else {
            //la API parece que no devuelve nada
            $http_code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); 

            curl_close($curl);

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Atención, respuesta sin response de la API a petición api/orders/:order_id/documents para factura de pedido '.$this->id_order.' de order_id '.$this->order_id.' para marketplace '.ucfirst($this->marketplace).PHP_EOL, FILE_APPEND);

            $this->error = 1;
                
            $this->mensajes[] = 'Atención, respuesta sin response de la API a petición api/orders/:order_id/documents para factura de pedido '.$this->id_order.' de order_id '.$this->order_id.' para marketplace '.ucfirst($this->marketplace); 
            $this->mensajes[] = 'Http Response Code = '.$http_code;                           

            return false;
        }

    }

    //función que hace update a la tabla lafrips_mirakl_orders para marcar un pedido como enviado, o revisado el pago, etiquetado, factura gestionada, etc
    public function updateMiraklOrders($proceso) {
        if ($proceso == 'enviado') {
            
            $update = " enviado_prestashop = 1, 
                date_enviado_prestashop = NOW(),
                shipping_carrier_code = '".$this->shipping_info['carrier_standard_code']."',
                shipping_tracking = '".$this->shipping_info['tracking_number']."', 
                procesando = 0,
                date_procesando = '0000-00-00 00:00:00', ";

        } elseif ($proceso == 'confirmado') {
            //marcaremos confirmado envío             
            $update = " confirmado_envio = 1,
                date_confirmado_envio = NOW(), 
                procesando = 0,
                date_procesando = '0000-00-00 00:00:00', ";    

        } elseif ($proceso == 'revisado') {
            //no marcamos procesando = 0 porque se llama aquí durante el proceso de creación de pedido, justo después de comprobar que el pedido esta SHIPPING y antes de comenzar a crearlo. Además, solo haemos update a la fecha si es '0000-00-00 00:00:00' para saber si ya estaba revisado, y en creación manual no perder ese dato
            
            $update = " revisado_shipping = 1,
                date_revisado_shipping = IF(date_revisado_shipping = '0000-00-00 00:00:00' ,NOW() ,date_revisado_shipping), ";    

        } elseif ($proceso == 'creado') {
            if ($this->proceso_pedido_concreto) {
                //ponemos el id del empleado que ha lanzado la url y añdimos el canal, dado que no va en la url y si el pedido no existía en la tabla, no lo hemos metido al insertarlo
                $manual = " channel = '".$this->webservice_order_info['channel']."', 
                    creado_manual = 1, 
                    id_employee_manual = ".$this->id_employee_manual.", ";
            } else {
                $manual = "";
            }
            
            $update = " creado_prestashop = 1,     
                date_creado_prestashop = NOW(),            
                id_order = ".$this->prestashop_order_info['id_order'].",
                id_webservice_order = ".$this->prestashop_order_info['id_webservice_order'].",
                id_customer = ".$this->prestashop_order_info['id_customer'].",
                id_address_delivery = ".$this->prestashop_order_info['id_address_delivery'].",      
                procesando = 0,
                date_procesando = '0000-00-00 00:00:00',        
                ".$manual;                
        } elseif ($proceso == 'factura') {            
            
            $update = " factura = 1,
                date_factura = NOW(), 
                procesando = 0,
                date_procesando = '0000-00-00 00:00:00', ";     

        }

        $update_mirakl_orders = "UPDATE lafrips_mirakl_orders 
        SET 
        $update        
        date_upd = NOW()        
        WHERE order_id = '".$this->order_id."'
        AND marketplace = '".$this->marketplace."'";

        if (!Db::getInstance()->execute($update_mirakl_orders)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR haciendo update revisión "'.$proceso.'" para order_id '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' en tabla lafrips_mirakl_orders'.PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - ERROR haciendo update revisión "'.$proceso.'" para order_id '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' en tabla lafrips_mirakl_orders';
        }

        return;
    }

    //función para insertar los datos básicos de los pedidos que aceptamos de Mirakl
    public function insertMiraklOrder() {
        $sql_insert_mirakl_order = "INSERT INTO lafrips_mirakl_orders
        (order_id, marketplace, channel, aceptado_mirakl, date_aceptado_mirakl, date_add) 
        VALUES
        ('".$this->mirakl_order_info['order_id']."', '".$this->marketplace."', '".$this->mirakl_order_info['channel']."', 1, NOW(), NOW())";

        if (!Db::getInstance()->execute($sql_insert_mirakl_order)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR haciendo insert en lafrips_mirakl_orders para order_id '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace).PHP_EOL, FILE_APPEND); 
    
            $this->error = 1;
        
            $this->mensajes[] = ' - ERROR haciendo insert en lafrips_mirakl_orders para order_id '.$this->mirakl_order_info['order_id'].' de marketplace '.ucfirst($this->marketplace);
        }

        return;
    }
    
    //función que obtiene los pedidos a revisar. Dependiendo de lo que queremos revisar filtraremos la sql. Para la revisión de envío podemos sacar directamente el estado del pedido en Prestashop de modo que no es necesario hacer una segunda consulta, dado que estos pedidos ya existen en Prestashop 
    public function getPedidos($revision) {
        $this->pedidos_revisar = null;

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Buscando pedidos para revisar '.strtoupper($revision).' para marketplace '.ucfirst($this->marketplace).PHP_EOL, FILE_APPEND);  

        if ($revision == 'confirmado shipping') {
            $select = '';
            //sacamos aquellos donde hayan pasado al menos 3 minutos desde que se aceptaron, así evitamos los que han sido aceptados en este mismo proceso
            $condicion = ' AND mor.aceptado_mirakl = 1 
                AND mor.revisado_shipping = 0 
                AND mor.creado_prestashop = 0
                AND TIMESTAMPDIFF(MINUTE, mor.date_aceptado_mirakl, NOW()) >= 2';

        } elseif ($revision == 'enviado prestashop') {
            //sacamos aquellos donde hayan pasado al menos 3 minutos desde que se creó el pedido, así evitamos los que han sido creados en este mismo proceso y por tanto no puede estar enviado
            $select = ', mor.id_order, ord.current_state';
            $condicion = ' AND mor.creado_prestashop = 1 
            AND mor.enviado_prestashop = 0
            AND TIMESTAMPDIFF(MINUTE, mor.date_creado_prestashop, NOW()) >= 2';

        } elseif ($revision == 'confirmado envio') {
            //de momento no ponemos un plazo de tiempo para sacar los pedidos recién confirmado su shipping porque parece ser inmediato y así se confirma en el mismo proceso
            $select = ', mor.id_order';
            $condicion = ' AND mor.creado_prestashop = 1 AND mor.enviado_prestashop = 1 AND mor.confirmado_envio = 0';

        } elseif ($revision == 'factura') {
            
            $select = ', mor.id_order';
            $condicion = ' AND mor.creado_prestashop = 1 AND mor.enviado_prestashop = 1 AND mor.confirmado_envio = 1 AND mor.factura = 0';

        }

        //buscamos en lafrips_mirakl_orders los pedidos no cancelados que cumplan la condición
        $sql_pedidos_revisar = "SELECT mor.id_mirakl_orders, mor.order_id ".$select."
        FROM lafrips_mirakl_orders mor
        LEFT JOIN lafrips_orders ord ON ord.id_order = mor.id_order
        WHERE mor.cancelado = 0 
        AND mor.procesando = 0
        AND mor.marketplace = '".$this->marketplace."'
        ".$condicion;

        $this->pedidos_revisar = Db::getInstance()->executeS($sql_pedidos_revisar);      

        if (!$this->pedidos_revisar || count($this->pedidos_revisar) < 1) {           

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - No hay pedidos para revisar '.strtoupper($revision).' para marketplace '.ucfirst($this->marketplace).PHP_EOL, FILE_APPEND);     
            
            return false;
        } else {
            //marcamos procesando = 1 a las líneas obtenidas
            foreach ($this->pedidos_revisar AS $pedido) {
                $this->setProcesando($pedido['id_mirakl_orders'], true);
            }
        }

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Encontrados '.count($this->pedidos_revisar).' pedidos para revisar '.strtoupper($revision).' para marketplace '.ucfirst($this->marketplace).PHP_EOL, FILE_APPEND); 

        return true;
    }

    //función que pone o quita procesndo de la tabla mirakl_orders
    public function setProcesando($id_mirakl_order, $procesando) {
        if ($procesando) {
            Db::getInstance()->Execute("UPDATE lafrips_mirakl_orders 
                SET procesando = 1, 
                date_procesando = NOW() 
                WHERE id_mirakl_orders = $id_mirakl_order");    
        } else {
            Db::getInstance()->Execute("UPDATE lafrips_mirakl_orders 
                SET procesando = 0, 
                date_procesando = '0000-00-00 00:00:00'
                WHERE id_mirakl_orders = $id_mirakl_order");    
        }

        return;
    }

    //función que revisa los posibles pedidos que tienen procesando = 1 en este punto de la ejecución, que han de ser errores. Se avisa si se encuentra alguno. Como puede darse el error de dos ejecuciones demasiado seguidas del proceso, razón por la que usamos el campo procesando, podríamos encontrar pedidos con procesando = 1 que acaba de poner así un segundo proceso. Será raro pero por error en el cron podría suceder, de modo que aquellos pedidos con date_procesando superior a hace 30 minutos serán considerados error.
    public function checkProcesando() {
        $sql_pedidos_procesando = 'SELECT id_mirakl_orders, order_id, marketplace, date_procesando 
        FROM lafrips_mirakl_orders 
        WHERE procesando = 1
        AND error = 0
        AND TIMESTAMPDIFF(MINUTE, date_procesando, NOW()) > 30';

        $pedidos_procesando = Db::getInstance()->executeS($sql_pedidos_procesando);

        if (count($pedidos_procesando) > 0) { 
            foreach ($pedidos_procesando AS $pedido) {               
                
                $this->error = 1;
                $this->mensajes[] = "- WARNING, el pedido order_id ".$pedido['order_id']." de marketplace ".ucfirst($pedido['marketplace'])." permanece en estado procesando desde ".$pedido['date_procesando'];            

                file_put_contents($this->log_file, date('Y-m-d H:i:s')." - WARNING, el pedido order_id ".$pedido['order_id']." de marketplace ".ucfirst($pedido['marketplace'])." permanece en estado procesando desde ".$pedido['date_procesando'].PHP_EOL, FILE_APPEND);  
                
                Db::getInstance()->Execute("UPDATE lafrips_mirakl_orders
                    SET
                    error = 1, 
                    date_error = NOW(),                    
                    error_message = CONCAT(error_message, ' - Permanece en estado Procesando - ', DATE_FORMAT(NOW(),'%d-%m-%Y %H:%i:%s')),                              
                    date_upd = NOW()
                    WHERE id_mirakl_orders = ".$pedido['id_mirakl_orders']);  

                continue;
            }
        }

        return;
    }
    
    //función para buscar los marketplaces activos que recorreremos para buscar y procsar pedidos
    public function getMarketplaces() {
        $sql_marketplaces = "SELECT * FROM lafrips_mirakl_marketplaces WHERE active = 1";

        $this->marketplaces = Db::getInstance()->executeS($sql_marketplaces);      

        if (!$this->marketplaces || !is_array($this->marketplaces) || count($this->marketplaces) < 1) {
            $this->error = 1;
            $this->mensajes[] = "No se pudo obtener la información de los marketplaces desde la Base de Datos";            

            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - No se pudo obtener la información de los marketplaces desde la Base de Datos'.PHP_EOL, FILE_APPEND);     
            
            return false;
        }

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Información de marketplaces obtenida correctamente'.PHP_EOL, FILE_APPEND);

        return true;
    }

    //función para comprobar si un producto de línea de pedido está disponible o no, obteniendo primero sus ids
    public function productoDisponible($referencia_prestashop, $quantity) {
        //priemro obtenemos sus ids en Prestashop
        $ids_producto = $this->getIdsProducto($referencia_prestashop);

        if ($ids_producto == false) {
            return false;
        }

        //sacamos el stock disponible online
        $stock_available = $this->getStockAvailableOnline($ids_producto[0], $ids_producto[1]);   

        //si no hay stock suficiente y el producto no permite venta sin stock es error de stock
        //la función getProductOutOfStockByAttribute() devuelve el valor de out_of_stock (0 1 o 2) para la combinación id_product id_product_attribute teniendo en cuenta atributos.
        if (($stock_available < $quantity) && ($this->getProductOutOfStockByAttribute($ids_producto[0], $ids_producto[1]) != 1)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR, sku_prestashop '.$referencia_prestashop.' de pedido order_id = '.$this->mirakl_order_info['order_id'].' para marketplace '.ucfirst($this->marketplace).' no dispone de stock suficiente para el pedido'.PHP_EOL, FILE_APPEND);    
    
            $this->error = 1;
        
            $this->mensajes[] = ' - ERROR, sku_prestashop '.$referencia_prestashop.' de pedido order_id = '.$this->mirakl_order_info['order_id'].' para marketplace '.ucfirst($this->marketplace).' no dispone de stock suficiente para el pedido'; 
             
            return false;
        }

        return true;
    }

    //función que con la sku_prestashop devuelve los datos de un producto, id_product, id_product_attribute
    public function getIdsProducto($referencia_prestashop) {        
        //como es muy lento buscar en product y product_attribute por la cadena de reference buscamos en las tablas por separado
        //primero buscamos en lafrips_product
        $sql_product = "SELECT id_product
        FROM lafrips_product       
        WHERE reference = '".$referencia_prestashop."'";
        
        $product = Db::getInstance()->executeS($sql_product);       

        //si devuelve más de un resultado puede ser error porque haya varios productos con la misma referencia o puede ser que tenemos la referencia base de un producto con atributos, en cuyo caso nos devuelve los datos de todos los atributos, en ambos casos es error y hay que señalarlo
        if (count($product) > 1) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR, sku_prestashop '.$referencia_prestashop.' de pedido order_id = '.$this->mirakl_order_info['order_id'].' para marketplace '.ucfirst($this->marketplace).' corresponde a '.count($product).' productos como referencia base'.PHP_EOL, FILE_APPEND);    
    
            $this->error = 1;
        
            $this->mensajes[] = ' - ERROR, sku_prestashop '.$referencia_prestashop.' de pedido order_id = '.$this->mirakl_order_info['order_id'].' para marketplace '.ucfirst($this->marketplace).' corresponde a '.count($product).' productos como referencia base';            
            
            return false;
        } elseif (count($product) < 1) {
            //la buscamos en lafrips_product_attribute
            $sql_product_attribute = "SELECT id_product, id_product_attribute
            FROM lafrips_product_attribute       
            WHERE reference = '".$referencia_prestashop."'";
            
            $product_attribute = Db::getInstance()->executeS($sql_product_attribute);

            if (count($product_attribute) > 1) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR, sku_prestashop '.$referencia_prestashop.' de pedido order_id = '.$this->mirakl_order_info['order_id'].' para marketplace '.ucfirst($this->marketplace).' corresponde a '.count($product_attribute).' productos como referencia de atributo'.PHP_EOL, FILE_APPEND);        
        
                $this->error = 1;
            
                $this->mensajes[] = ' - ERROR, sku_prestashop '.$referencia_prestashop.' de pedido order_id = '.$this->mirakl_order_info['order_id'].' para marketplace '.ucfirst($this->marketplace).' corresponde a '.count($product_attribute).' productos como referencia de atributo';    
    
                return false;
            } elseif (count($product_attribute) < 1) {
                file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR, sku_prestashop '.$referencia_prestashop.' de pedido order_id = '.$this->mirakl_order_info['order_id'].' para marketplace '.ucfirst($this->marketplace).' no se encuentra en base de datos de Prestashop'.PHP_EOL, FILE_APPEND);                            
        
                $this->error = 1;
            
                $this->mensajes[] = ' - ERROR, sku_prestashop '.$referencia_prestashop.' de pedido order_id = '.$this->mirakl_order_info['order_id'].' para marketplace '.ucfirst($this->marketplace).' no se encuentra en base de datos de Prestashop';   

                return false;
            } else {
                //se ha encontrado una sola correspondencia. tenemos (id_product, id_product_attribute)
                $id_product = $product_attribute[0]['id_product'];
                $id_product_attribute = $product_attribute[0]['id_product_attribute'];

                return array($id_product, $id_product_attribute); 
            }
            
        } else {
            //tenemos un id_product de lafrips_product, el id_product_attribute debe ser 0. 
            $id_product = $product[0]['id_product'];
            $id_product_attribute = 0;

            return array($id_product, $id_product_attribute);            
        }
    }
    
    //función que devuelve el stock disponible ONLINE para un producto dado el id_product e id_product_attribute, para almacén online id 1
    public function getStockAvailableOnline($id_product, $id_product_attribute) {
        //primero obtenemos el stock disponible total
        $stock_total_disponible = StockAvailable::getQuantityAvailableByProduct($id_product, $id_product_attribute);
        //después obtenemos el stock físico del almacén de tienda, enviamos el id de almacén como array
        $ids_warehouse = array(4);
        $stock_fisico_tienda = StockManager::getProductPhysicalQuantities($id_product, $id_product_attribute, $ids_warehouse);

        //como en tienda no se retienen productos en pedidos etc, podemos obtener el stock disponible online restando al total el stock físico de tienda
        return $stock_total_disponible - $stock_fisico_tienda;
    }

    //función clonada de StockAvailable::outOfStock() que devolvía out_of_stock solo para id_product_attribute = 0 y no podías ver el del atributo. Devuelve el valor de out_of_stock 0 1 o 2
    public function getProductOutOfStockByAttribute($id_product, $id_product_attribute = 0, $id_shop = null) {
        if (!Validate::isUnsignedId($id_product)) {
            return false;
        }

        $query = new DbQuery();
        $query->select('out_of_stock');
        $query->from('stock_available');
        $query->where('id_product = '.(int)$id_product);
        $query->where('id_product_attribute = '.(int)$id_product_attribute); //aquí modificación, antes era 'id_product_attribute = 0'

        $query = StockAvailable::addSqlShopRestriction($query, $id_shop);

        return (int)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query);
    }
 
    //función que limpia el servidor de archivos antiguos de log y prepara el de esta sesión
    public function setLog() {
        //primero comprobamos si estamos en el procso para crear un solo pedido especificado, si no continuamos normal
        if ($this->proceso_pedido_concreto) {
            //preparamos nuevo archivo para esta sesión
            $this->log_file = $this->log_path.'manuales/proceso_pedido_manual_'.$this->order_id.'_'.$this->marketplace.'_'.date('Y-m-d H:i:s').'.txt';
                    
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso de creación de pedido específico para marketplaces Mirakl'.PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Id empleado = '.$this->id_employee_manual.PHP_EOL, FILE_APPEND); 
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Marketplace '.ucfirst($this->marketplace).' - order_id = '.$this->order_id.PHP_EOL, FILE_APPEND);  

            return;
        }

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
        $this->log_file = $this->log_path.'proceso_pedidos_marketplaces_mirakl_'.date('Y-m-d H:i:s').'.txt';
                   
        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Comienzo proceso de revisión de pedidos para marketplaces Mirakl'.PHP_EOL, FILE_APPEND);       
        
        $sql_marketplaces_names = "SELECT marketplace FROM lafrips_mirakl_marketplaces WHERE active = 1";

        $marketplaces_names = Db::getInstance()->executeS($sql_marketplaces_names);   
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
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - ERROR obteniendo credenciales para marketplaces, abortando proceso'.PHP_EOL, FILE_APPEND); 

            $this->error = 1;
            
            $this->mensajes[] = ' - ERROR obteniendo credenciales para marketplaces, abortando proceso'; 

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

        file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Credenciales de marketplaces Mirakl obtenidas correctamente'.PHP_EOL, FILE_APPEND); 

        return true;        
    }

      
    //Envía un email con el contenido de los mensajes de error
    public function enviaEmail($productos_sin_stock = null) {
        $mensaje_email = array();

        if (!empty($this->mensajes)) {
            $mensaje_email = $this->mensajes;
        }

        $asunto = 'ERROR proceso de pedidos de marketplaces Mirakl '.date("Y-m-d H:i:s");
        $cuentas = 'sergio@lafrikileria.com';

        if ($productos_sin_stock !== null) {
            $cuentas = array('sergio@lafrikileria.com', 'alberto@lafrikileria.com');
            $asunto = 'ERROR producto/s sin stock en pedido '.$this->order_id.' de marketplace '.ucfirst($this->marketplace).' - Mirakl '.date("Y-m-d H:i:s");
            $mensaje_email[] = 'Pedido no confirmado';
            $mensaje_email[] = 'Producto/s sin suficiente stock en el almacén online';
            $mensaje_email[] = $productos_sin_stock;
        }
        
        if ($this->log && ($productos_sin_stock == null)) {
            file_put_contents($this->log_file, date('Y-m-d H:i:s').' - Fin del proceso, dentro de enviaEmail '.PHP_EOL, FILE_APPEND);  
        }            

        
        $info = [];                
        $info['{employee_name}'] = 'Usuario';
        $info['{order_date}'] = date("Y-m-d H:i:s");
        $info['{seller}'] = "";
        $info['{order_data}'] = "";
        $info['{messages}'] = '<pre>'.print_r($mensaje_email, true).'</pre>';
        
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

        // exit;
        return;
    }




}

unset($a);


