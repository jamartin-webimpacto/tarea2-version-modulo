<?php
/**
* 2007-2020 PrestaShop
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
*  @copyright 2007-2020 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Importproductstheircategoriescsv extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'importproductstheircategoriescsv';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'jamartin';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Import products and their categories');
        $this->description = $this->l('Whit this modulo you can import products from csv to your shop with one click');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
         return parent::install() &&
            $this->registerHook('backOfficeHeader');
    }

    public function uninstall()
    {
        Configuration::deleteByName('IMPORTPRODUCTSTHEIRCATEGORIESCSV_LIVE_MODE');

        return parent::uninstall();
    }

    public function hookActionProductAdd($params)
    {
        $this->_clearCache('*');
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $html='';
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('uploadproducts_csv')) == true) {
            $html = $this->postProcess();
        } else { //Eliminar los registros realizados cuando ha habido error
           // $this->borrar();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        /*
         * Ya no necesitamos la plantilla pues el formulario se crea desde el helper
         */
        //$output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $html.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        
        $helper->languages = $this->context->controller->getLanguages();
        $helper->default_form_language = $this->context->controller->default_form_language;
        $helper->allow_employee_form_lang = $this->context->controller->allow_employee_form_lang;
        $helper->title = $this->displayName;
        $helper->submit_action = 'uploadproducts_csv';

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
                'title' => $this->displayName,
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'file',
                        'desc' => $this->l('Enter a csv file'),
                        'name' => 'upload_file',
                        'label' => $this->l('CSV'),
                        'accept' => $this->l('.csv'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }



    /**
     * Save form data.
     */
    protected function postProcess()
    {
        //Obteniendo archivo
        $csv = $_FILES['upload_file']['tmp_name'];
        
        //Leyendo archivo a un array
        $fp = fopen($csv, "r");
        $data = [];
        while ($data[] = fgetcsv($fp, 1000, ",")) {
        }
        fclose($fp);
        
        //Eliminamos el ultimo elemnto del array que estara vacío
        array_pop($data);
        
        //Contamos los elementos del array
        $numProducts = count($data) - 1;
        
        //Obteniendo ids lenguages activos
        $langs=[];
        foreach (Language::getLanguages(true) as $language) {
            $langs[] = $language['id_lang'];
        }
        
        //Inicio importacion
        for ($i=1; $i<=$numProducts; $i++) {
            //Preparando el array product->name
            $names = [];
            foreach ($langs as $lang) {
                $names[$lang] = $data[$i][0];
            }
            $product = new Product();
            $product->name = $names;
            $product->reference = $data[$i][1];
            $product->ean13 = $data[$i][2];
            $product->wholesale_price = (float)$data[$i][3];
            $product->price = (float)$data[$i][4];
            $product->redirect_type ='301-category';
            $impuestos = (float)$data[$i][5];//.'.000');
            $product->id_tax_rules_group = (int)$this->getIdTax($impuestos);
            $product->on_sale = 0;
            $product->id_manufacturer = (int)$this->getIdMarcaProducto($data[$i][8]);
            $product->add();
            StockAvailable::setQuantity($product->id, $product->id, (int)$data[$i][6]);

            //Obteniendo los id de las categorias y creando las necesarias
            $categories = explode(';', $data[$i][7]);
            $defaultCategory = 0;
            $idCategories = [];
            foreach ($categories as $category) {
                $idCategory = $this->getIdProductCategory(trim($category), $langs);
                if ($idCategory) {
                    if ($defaultCategory == 0) {
                        $product->id_category_default = $idCategory;
                    }
                    $idCategories[]=$idCategory;
                    $defaultCategory++;
                }
            }
            //dump($idCategories);
            if (count($idCategories)>0) {
                $product->addToCategories($idCategories);
            }
            //dump($product);
        }
        //Si todo va bien se devuelve confirmacion
        $html = '<div class="alert alert-success" role="alert">Upload CSV File Successfully</div>';
        return $html;
    }
    
    /**
    * Conseguir el grupo de id_tax al que pertenece un impuesto
    */
    public function getIdTax($tax)
    {
        $db = \Db::getInstance();
        $request = "SELECT id_tax "
                . "FROM "._DB_PREFIX_."tax "
                . "WHERE rate = $tax ";
        $id_tax = $db->getValue($request);
        return $id_tax;
    }
    
    /**
    * Obtener category_id si existe, sino la crea y devuelve el id
    */
    public function getIdProductCategory($name, $lang)
    {
        $result = $this->buscarCategoriaPorNombre(Configuration::get('PS_LANG_DEFAULT'), $name);
        //dump($result);
        if ($result) {
            return $result[0]['id_category'];
        } else {
            return $this->insertarCategoriaDB(pSQL($name), $lang);
        }
    }
    
    /*
    * Crear categoría
    */
    public function insertarCategoriaDB($name, $langs)
    {
        $newCategory = new \Category();
        $newCategory->active = 1;
        $names=[];
        $links_rewriters=[];
        foreach ($langs as $language) {
            $names[(int)$language]=$name;
            $links_rewriters[(int)$language]= $this->seoUrl($name);
        }
        $newCategory->name= $names;
        $newCategory->id_parent = 2;
        $newCategory->position = 1;
        $newCategory->description = '';
        $newCategory->is_root_category =1;
        $newCategory->meta_keywords = '';
        $newCategory->meta_description = '';
        $newCategory->link_rewrite = $links_rewriters;

        $id = $this->creaCategoriaBd((int)\Context::getContext()->shop->id);

        $newCategory->id_category = $id;
        $newCategory->id = $id;
        //dump($newCategory);
        $newCategory->update();
        

        return $id;
    }
    
    /*
    * Crea una tupla en BD de categoria
    */
    public function creaCategoriaBd($shopId)
    {
        $db = \Db::getInstance();

        $db->insert('category', array(
            'id_parent' => 1,
            'id_shop_default' => $shopId,
            'active' => 1,
            'is_root_category' => 1,
            'date_add' => date('Y-m-d H:i:s'),
        ));
        $id = Db::getInstance()->Insert_ID();
        return $id;
    }
    
    public function buscarCategoriaPorNombre($lang, $name)
    {
        $db = \Db::getInstance();
        $request = "SELECT id_category 
                FROM "._DB_PREFIX_."category_lang
                WHERE  name = '".$name."' AND id_lang = ".$lang;
        $id = $db->executeS($request);
        //dump($id);
        return $id;
    }
    
    /**
    * Obtener marca o instanciar una nueva si no existe
    */
    public function getIdMarcaProducto($nombreMarca)
    {
        if ($id = \Manufacturer::getIdByName($nombreMarca)) {
            return $id;
        } else {
            $db = \Db::getInstance();
            $db->insert('manufacturer', array(
                'name' => $nombreMarca,
                'date_upd' => date('Y-m-d H:i:s'),
            ));
            $this->getIdMarcaProducto($nombreMarca);
        }
    }
    
    /*
    * Convierte a url amigable el string para link_rewrite
    */
    public function seoUrl($cadena)
    {
        $cadena = utf8_decode($cadena);
        $cadena = str_replace(' ', '-', $cadena);
        $cadena = str_replace(',', '', $cadena);
        $cadena = str_replace('?', '', $cadena);
        $cadena = str_replace('+', '', $cadena);
        $cadena = str_replace(':', '', $cadena);
        $cadena = str_replace('??', '', $cadena);
        $cadena = str_replace('`', '', $cadena);
        $cadena = str_replace('!', '', $cadena);
        $cadena = str_replace('¿', '', $cadena);
        $originales = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿ??';
        $modificadas = 'aaaaaaaceeeeiiiidnoooooouuuuybsaaaaaaaceeeeiiiidnoooooouuuyybyRr';
        $cadena = strtr($cadena, utf8_decode($originales), $modificadas);
        //echo"<br/> Seo Categoria ".$cadena."<br/>";
        return $cadena;
    }

    /*
    * Funcion usada en desarrollo para eliminar de las tablas los registros
    * creados por el script cuando hay errores
    */
    public function borrar()
    {
        echo "Borrando....";
        $db = \Db::getInstance();
        $request = "DELETE FROM "._DB_PREFIX_."product "
                . " WHERE id_product > 19";
        $db->execute($request);

        $request = "DELETE FROM "._DB_PREFIX_."product_lang "
                . " WHERE id_product > 19";
        $db->execute($request);

        $request = "DELETE FROM "._DB_PREFIX_."product_shop "
                . " WHERE id_product > 19";
        $db->execute($request);

        $request = "DELETE FROM "._DB_PREFIX_."category"
                . " WHERE id_category > 11";
        $db->execute($request);

        $request = "DELETE FROM "._DB_PREFIX_."category_lang"
                . " WHERE id_category > 9";
        $db->execute($request);

        $request = "DELETE FROM "._DB_PREFIX_."category_shop"
                . " WHERE id_category > 11";
        $db->execute($request);

        $request = "DELETE FROM "._DB_PREFIX_."category_product"
                . " WHERE id_product > 19";
        $db->execute($request);

        echo "Borrado";
    }
}
