<?php
/**
* 2007-2024 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2024 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Miraklfrik extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'miraklfrik';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Sergio';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Mirakl Frikilería');
        $this->description = $this->l('Módulo de La Frikilería para gestionar las plataformas de Mirakl, catálogo y pedidos para los diferentes marketplaces y canales');

        $this->confirmUninstall = $this->l('');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.7');
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('MIRAKLFRIK_LIVE_MODE', false);

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('displayBackOfficeHeader');
            //  && $this->registerHook('actionAdminOrdersListingFieldsModifier');
    }

    public function uninstall()
    {
        Configuration::deleteByName('MIRAKLFRIK_LIVE_MODE');

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitMiraklfrikModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMiraklfrikModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Live mode'),
                        'name' => 'MIRAKLFRIK_LIVE_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Use this module in live mode'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-envelope"></i>',
                        'desc' => $this->l('Enter a valid email address'),
                        'name' => 'MIRAKLFRIK_ACCOUNT_EMAIL',
                        'label' => $this->l('Email'),
                    ),
                    array(
                        'type' => 'password',
                        'name' => 'MIRAKLFRIK_ACCOUNT_PASSWORD',
                        'label' => $this->l('Password'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'MIRAKLFRIK_LIVE_MODE' => Configuration::get('MIRAKLFRIK_LIVE_MODE', true),
            'MIRAKLFRIK_ACCOUNT_EMAIL' => Configuration::get('MIRAKLFRIK_ACCOUNT_EMAIL', 'contact@prestashop.com'),
            'MIRAKLFRIK_ACCOUNT_PASSWORD' => Configuration::get('MIRAKLFRIK_ACCOUNT_PASSWORD', null),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    //18/12/2024 Quitamos el hook por que hemos añadido los cambios a override AdminOrdersController
    /*
    public function hookActionAdminOrdersListingFieldsModifier($params)
    {
        
        // $log_file = _PS_ROOT_DIR_.'/modules/miraklfrik/log/pruebas_hook_'.date('Y-m-d H:i:s').'.txt';
                    
        // file_put_contents($log_file, date('Y-m-d H:i:s').' - Dentro hook'.PHP_EOL, FILE_APPEND);
        // $params_json = json_encode($params);
        // file_put_contents($log_file, date('Y-m-d H:i:s').$params_json.PHP_EOL, FILE_APPEND);

        

        //select para el id externo, un case para que valga para Amazon y Miraklfrik, y ponemos los de worten para los viejos, aunque ahora entrarán por miraklfrik. Para los de webservice cogemos de su tabla para que aparezcan también los de Disfrazzes por ejemplo
        $params['select'] .= ", CASE
                WHEN a.module = 'amazon' THEN mao.mp_order_id 
                WHEN a.module = 'mirakl' THEN mao.mp_order_id 
                WHEN a.module = 'WebService' THEN wor.external_id_order 
            ELSE ''
            END
            AS external_id";        

        $params['select'] .= ", CASE
                WHEN a.module = 'amazon' THEN mao.sales_channel 
                WHEN a.module = 'mirakl' THEN 'Worten' 
                WHEN a.module = 'WebService' THEN wor.origin 
            ELSE ''
            END
            AS origin";

        $params['select'] .= ", CASE
                WHEN a.module = 'amazon' THEN mao.latest_ship_date 
                WHEN a.module = 'mirakl' THEN mao.latest_ship_date 
                WHEN a.module = 'WebService' THEN wor.shipping_deadline 
            ELSE ''
            END
            AS latest_ship_date";


        //join a tabla lafrips_marketplace_orders que almacena order_id de amazon y su latest_ship_date
        $params['join'] .= " LEFT JOIN lafrips_marketplace_orders mao ON (a.`id_order` = mao.`id_order`)";
        //join a lafrips_webservice_orders donde alamcenamos  shipping_deadline
        $params['join'] .= " LEFT JOIN lafrips_webservice_orders wor ON (a.`id_order` = wor.`id_order`)";

        $params['fields']['origin'] = array(
            'title' => 'Origen',
            'align' => 'text-center',
            'type' => 'text',                
            'class' => 'fixed-width-md',
            'orderby' => false,
            'havingFilter' => true,                        
            'filter_type' => 'text'
        );

        $params['fields']['external_id'] = array(
            'title' => 'ID Externo',
            'align' => 'text-center',
            'type' => 'text',                
            'class' => 'fixed-width-md',
            'orderby' => false,
            'havingFilter' => true,                        
            'filter_type' => 'text'
        );

        $params['fields']['latest_ship_date'] = array(
            'title' => 'Envío',
            'align' => 'text-center',
            'type' => 'text',                
            'class' => 'fixed-width-md',
            'orderby' => false,
            'havingFilter' => true,                          
            'filter_type' => 'text',
        );
    }
        */
}
