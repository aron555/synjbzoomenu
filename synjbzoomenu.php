<?php
/**
 * @package    SYNJBZOOMENU
 * @author     Dmitry Tsymbal <cymbal@delo-design.ru>
 * @copyright  Copyright © 2019 Delo Design. All rights reserved.
 * @license    GNU General Public License version 3 or later; see license.txt
 * @link       https://delo-design.ru
 */

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

/**
 * plgSystemSynjbzoomenu plugin.
 *
 * @package   synjbzoomenu
 * @since     1.0.0
 */
class plgSystemSynjbzoomenu extends CMSPlugin
{

    /**
     * @since  1.0.0
     * @var array
     */
    protected $messages = [];

    /**
     * @since  1.0.0
     * Application object
     *
     * @var    CMSApplication
     */
    protected $app;


    /**
     * Database object
     *
     * @var    DatabaseDriver
     * @since  1.0.0
     */
    protected $db;


    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;


    protected $needCatalog;
    protected $typeInit;
    protected $menuTypeFromConfig;
    protected $appSource;

    protected $linkMenuFrontapage;
    protected $linkMenuCategory;



    /**
     * @param $subject
     * @param $config
     * @since 1
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->getConfig();
        $this->setConfig();
        $this->getToolbarButtons();
    }

    /* Тут все парамеры из настроек плагина*/
    public function getConfig()
    {
        /*Переменные с конфига*/
        $this->typeInit = $this->params->get('typeinit', 'menu');
        $this->menuTypeFromConfig = $this->params->get('syncMenu', 'nomenu');
        $this->needCatalog = $this->params->get('needCatalog', '1'); // нужен ли каталог
        $this->appSource = $this->params->get('application', '0:0'); //
        /*Переменные с конфига*/
    }

    /* Тут все парамеры из настроек плагина*/
    public function setConfig()
    {
        /*Переменные jbzoo*/
        $this->linkMenuFrontapage = "index.php?option=com_zoo&view=frontpage&layout=frontpage";
        $this->linkMenuCategory = "index.php?option=com_zoo&view=category&layout=category";
        /*Переменные*/
    }

    /**
     * Добавляем кнопки в тулбар
     *
     * @throws  Exception
     *
     * @since  1.0.0
     */

    public function getToolbarButtons()
    {
        $admin = $this->app->isClient('administrator');
        $option = $this->app->input->getCmd('option');
        $controller = $this->app->input->getCmd('controller');

        if ($admin && $option === 'com_zoo' && $controller == "category") {

            $toolbar = Toolbar::getInstance('toolbar');

            //добавляем проверу от случайных нажатий на кнопку, которая очищает меню
            $message = Text::_('PLG_SYNJBZOOMENU_MESSAGE_CONFIRMDELETE');
            Factory::getDocument()->addScriptDeclaration(<<<EON
document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('.button-syncAndTrash').addEventListener('click', function(ev) {
    	ev.preventDefault();
			
		if (confirm('{$message}')) {
			window.location.href = this.getAttribute('href');
		}
    });
});
EON
            );

            $root = Uri::getInstance()->toString(array('scheme', 'host', 'port'));

            $url = $root . '/administrator/index.php?' . http_build_query([
                    'option' => 'com_ajax',
                    'plugin' => 'SYNJBZOOMENU',
                    'group' => 'system',
                    'format' => 'raw',
                    'task' => 'sync',
                    'backPage' => $_SERVER['REQUEST_URI']
                ]);

            $button = '<a href="' . $url . '" class="btn btn-small">'
                . '<span class="icon-refresh" aria-hidden="true"></span>'
                . Text::_('PLG_SYNJBZOOMENU_BUTTON_SYNC') . '</a>';
            $toolbar->appendButton('Custom', $button, 'generate');


            $url2 = $root . '/administrator/index.php?' . http_build_query([
                    'option' => 'com_ajax',
                    'plugin' => 'SYNJBZOOMENU',
                    'group' => 'system',
                    'format' => 'raw',
                    'task' => 'syncAndTrash',
                    'backPage' => $_SERVER['REQUEST_URI']
                ]);

            $button2 = '<a href="' . $url2 . '" class="btn btn-small button-syncAndTrash">'
                . '<span class="icon-refresh" aria-hidden="true"></span>'
                . Text::_('PLG_SYNJBZOOMENU_BUTTON_SYNC_AND_CLEAN') . '</a>';
            $toolbar->appendButton('Custom', $button2, 'generate');
        }
    }

    /**
     * @since 1.0.0
     * Контроллер выбранного действия по нажатию кнопки в админке
     * Аякс
     * Только синхронизация или полное обновление
     */
    public function onAjaxSynjbzoomenu()
    {
        $task = $this->app->input->getCmd('task');
        $backPage = $this->app->input->getString('backPage');

        if ($task === 'sync') {
            $this->sync();
        }

        if ($task === 'syncAndTrash') {
            $this->trashMenu();
            $this->sync();
        }

        $this->app->redirect($backPage);

    }


    /**
     * @since 1
     * Синхронизация меню с категориями
     *
     * алгоритм сравнивания
     * проходим по категориям
     * если найден пункт меню с категорий, то сравниваем, если есть различия
     * (смотрим по названию и алиасу, создавать ли редиректы при смене алиаса?), то обновнляем пункт меню
     * если не найден пункт меню, а категория существует, то создаем пункт меню
     * если пункт меню есть, а категории нет, то удаляем пункт меню
     * */

    protected function sync()
    {
        /* Создадим пункт меню каталог, если он нужен, он выбран*/
        if ($this->needCatalog == "1" && $this->appSource == "1:0") {
            $this->addItemCatalog();
        }

        /* Загружаем таблицу */
        //выбираем меню
        $menuTable = JTableNested::getInstance('Menu');
        $menuTable->load([
            'menutype' => $this->menuTypeFromConfig,
            'link' => $this->linkMenuCategory
        ]);
        $menuItems = [];

        //выбираем пункты меню
        $db = Factory::getDbo();
        $query = $db
            ->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('menutype') . ' = ' . $db->quote($this->menuTypeFromConfig))
            ->where($db->quoteName('link') . ' = ' . $db->quote($this->linkMenuCategory))
        ;
        $db->setQuery($query);
        $menuItemsSource = $db->loadAssocList();

        //подготавливаем дерево пунктов меню для синхронизации
        if (!empty($menuItemsSource)) {

            foreach ($menuItemsSource as $menuItemSource) {
                $categoryId = $this->categoryFromJson($menuItemSource);
                $menuItems[$categoryId] = (array)$menuItemSource;
            }
        }


        //очищаем не нужную переменную
        unset($menuItemsSource);

        /* Загружаем объект с данными. Категории jbzoo */
        $categoryItems = $this->getExtension();


        /*Для всех категории запускаем синхронизацию с меню*/
        foreach ($categoryItems as $categoryItem) {
            $this->syncMenuItem($menuItems, $categoryItem); // (Массив пунктов меню по ключу ID категории, категория, тип меню)
        }

        $this->cleanMenu($menuItems, $categoryItems);
        $this->messagesBuild();
        $menuTable->rebuildPath($menuItems);

    }


    /**
     * Этот метод создает или обновляет пункт меню в зависимости от категории
     * @param array $menuItems
     * @param object $categoryItem
     * @param int $menuType
     * @since 1
     */
    protected function syncMenuItem(&$menuItems, $categoryItem)
    {

        if (isset($menuItems[$categoryItem->id])) { /*Если существует пункт меню с ид как у категории*/

            //обновление записи
            $flagUpdate = false;
            $menuItemUpdate = (array)$menuItems[(int)$categoryItem->id];
            //TODO
            //запуск поиска различий в парараметрах

            if (isset($menuItems[(int)$categoryItem->parent])) {

                $menuItemUpdateParent = $menuItems[(int)$categoryItem->parent];
                //проверяем поменялась ли родительская категория

                $categoryIdParent = $this->categoryFromJson($menuItemUpdateParent);

                if ($categoryIdParent !== (int)$categoryItem->parent) {
                    $flagUpdate = true;
                }
            }

            //проверяем другие поля меню на измененные значения
            $fields = [
                'title' => $categoryItem->name,
                'alias' => $categoryItem->alias,
            ];

            foreach ($fields as $key => $value) {

                if ($menuItemUpdate[$key] !== $value) {
                    $flagUpdate = true;
                    $menuItemUpdate[$key] = $value;
                }
            }

            //сохраняем, если есть изменения
            if ($flagUpdate) {
                $menuTable = JTableNested::getInstance('Menu');
                if ($menuTable->save($menuItemUpdate)) {
                    $this->addMessageCount('updateItem');
                }
            }

        } else {
            /*Если не существует пункт меню с id категории*/
            //создаем пункт меню

            $catalogTable = JTableNested::getInstance('Menu');
            $catalogTable->load([
                'menutype' => $this->menuTypeFromConfig,
                'link' => $this->linkMenuFrontapage
            ]);

            $catalogId = $catalogTable->get("id");

            $menuItemNew['params']['category'] = $categoryItem->id;
            $menuItemNew['params']['application'] = $categoryItem->application_id;
            $menuItemNew['params']['metadata.title'] = $categoryItem->name;
            $menuItemNew['params']['metadata.description'] = $categoryItem->description;
            $menuItemNew['params']['metadata.keywords'] = $categoryItem->description;

            $menuItemNew['params'] = json_encode($menuItemNew['params']);

            $menuItemNew['menutype'] = $this->menuTypeFromConfig;
            $menuItemNew['published'] = $categoryItem->published;
            $menuItemNew['title'] = $categoryItem->name;
            $menuItemNew['alias'] = $categoryItem->alias;
            $menuItemNew['type'] = 'component';
            $menuItemNew['component_id'] = JComponentHelper::getComponent('com_zoo')->id;
            $menuItemNew['language'] = '*';
            $menuItemNew['link'] = $this->linkMenuCategory;
            $menuItemNew['access'] = '1';

            $menuTable = JTableNested::getInstance('Menu');

            if(!isset($menuItems[(int)$categoryItem->parent]) || $categoryItem->parent == "0") {

                $menuTable->setLocation($catalogId, 'last-child');
            } else {
                $menuParent = $menuItems[(int)$categoryItem->parent];
                $menuTable->setLocation($menuParent['id'], 'last-child');
            }

            if ($menuTable->save($menuItemNew)) {
                $this->addMessageCount('createItem');
            } else {
                $this->app->enqueueMessage($categoryItem->name, 'error');
                return false;
            }

            $menuItemNew['id'] = $menuTable->id;
            $menuItemNew['params'] = json_decode($menuItemNew['params'], true);

            $menuItems[(int)$categoryItem->id] = $menuItemNew;

        }

    }

    /**
     * Удаляет лишние пункты меню, если удалены их категории
     * @param array $menuItems
     * @param object $categories
     * @since 1
     */
    protected function cleanMenu(&$menuItems, &$categories)
    {
        $ids = [];
        foreach ($categories as $category) {
            $ids[] = (int)$category->id;
        }
        foreach ($menuItems as $menuItem) {

            if (!in_array((int)$menuItem, $ids, true)) {
                //удаляем пункт меню

                $db = Factory::getDbo();
                $query = $db->getQuery(true);
                $conditions = [
                    $db->quoteName('id') . ' = ' . (int)$menuItem['id']
                ];
                $query->delete($db->quoteName('#__menu'));
                $query->where($conditions);
                $db->setQuery($query);
                $db->execute();
                // /*TODO*/

                $this->addMessageCount('deleteItem');

            }
        }
    }


    /**
     * Очищает от всех пунктов меню
     */
    protected function trashMenu()
    {
        $menuType = $this->menuTypeFromConfig;

        if ($menuType === 'nomenu') {
            $this->app->enqueueMessage(Text::_('PLG_SYNJBZOOMENU_ERROR_NOMENU'), 'error');
            return false;
        }

        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $conditions = [
            $db->quoteName('menutype') . ' = ' . $db->quote($menuType),
        ];

        $query->delete($db->quoteName('#__menu'));
        $query->where($conditions);
        //$query->where('id NOT IN (' . implode(', ', $exclude) . ')');
        $db->setQuery($query);
        $db->execute();
        $this->app->enqueueMessage(Text::_('PLG_SYNJBZOOMENU_TRASHMENU'));

        //TODO спросить при очистке надо ли найти старые пункты меню и заменить их в базе данных

    }


    /**
     * Добавляем счетчик к сообщениям
     * @param $type
     */
    protected function addMessageCount($type)
    {
        if (!isset($this->messages[$type])) {
            $this->messages[$type] = 0;
        }

        $this->messages[$type]++;

    }


    /**
     * Собираем сообщения в общее для уведомления
     */
    protected function messagesBuild()
    {
        $messageOutput = '';

        foreach ($this->messages as $type => $count) {
            $messageOutput .= Text::_('PLG_SYNJBZOOMENU_MESSAGE_' . strtoupper($type)) . ': ' . $count . '<br/>';
        }

        $this->app->enqueueMessage($messageOutput);
    }

    protected function addItemCatalog()
    {
        $menuCatalog = JTableNested::getInstance('Menu');

        if ($this->appSource == "1:0") { /*TODO придумать как по значению получать свойства категорий*/
            $catalogName = "Каталог";
            $catalogAlias = "catalog";
        }

        $menuCatalogData = [
            'menutype' => $this->menuTypeFromConfig,
            'published' => "1",
            'title' => $catalogName,
            'alias' => $catalogAlias,
            'type' => 'component',  /* внутренний тип меню*/
            'component_id' => JComponentHelper::getComponent('com_zoo')->id,     /* ID компонента в #__extensions  */
            'language' => '*',
            'level' => '1',
            'parent_id' => '1',
            'link' => $this->linkMenuFrontapage,
            'params' => '{"application":"1","menu-anchor_title":"","menu-anchor_css":"","menu_image":"","menu_image_css":"","menu_text":1,"menu_show":1,"page_title":"","show_page_heading":"","page_heading":"","pageclass_sfx":"","menu-meta_description":"","menu-meta_keywords":"","robots":"","secure":0,"helixultimatemenulayout":"{\"width\":600,\"menualign\":\"right\",\"megamenu\":0,\"showtitle\":1,\"faicon\":\"\",\"customclass\":\"\",\"dropdown\":\"right\",\"badge\":\"\",\"badge_position\":\"\",\"badge_bg_color\":\"\",\"badge_text_color\":\"\",\"layout\":[]}","helixultimate_enable_page_title":"0","helixultimate_page_title_alt":"","helixultimate_page_subtitle":"","helixultimate_page_title_heading":"h2","helixultimate_page_title_bg_color":"","helixultimate_page_title_bg_image":""}',
        ];
        $menuCatalog->setLocation("1", 'last-child');
        if ($menuCatalog->save($menuCatalogData)) {
            $this->addMessageCount('createItem');

        } else {
            $this->app->enqueueMessage("Каталог был создан ранее", 'warning');
        }
    }

    /* Возвращает катагорию из параметров пункта меню*/
    public function CategoryFromJson($menuItem)
    {
        if (in_array($menuItem['params'], $menuItem ) && $menuItem['params'] != NULL) {
            if ($menuItem['link'] == $this->linkMenuCategory) { //Только для категорий jbzoo
                $menuItemParams = json_decode($menuItem['params'], true);
                if (isset($menuItemParams['category'])) {
                    return (int)$menuItemParams['category'];
                }
            } else {
                $this->app->enqueueMessage("Пункт меню " . $menuItem["title"] . " без категории", 'warning');
            }
        }
    }

    public function getExtension()
    {
        $ext = "zoo";
        if ($ext == "zoo") {
            return $this->getJbzooCategories();
        }
    }

    /* Должен вернуть объект категорий */
    public function getJbzooCategories()
    {
        // load zoo config
        if (file_exists(JPATH_ADMINISTRATOR . '/components/com_zoo/config.php')) {
            require_once(JPATH_ADMINISTRATOR . '/components/com_zoo/config.php');
        } else {
            $this->app->enqueueMessage("Ошибка чтения конфига ZOO. Попробуйте переустановить приложение ZOO", 'error');
            return false;
        }

        // get the ZOO App instance
        $zoo = App::getInstance('zoo');

        $applications = $zoo->application->getApplications();

        $categoriesData = [];

        if (!empty($applications)) {
            foreach ($applications as $application) {
                // Get Categories
                $categories = $application->getCategories(false);

                if (!empty($categories)) {
                    foreach ($categories as $row) {
                        // Prepare category object
                        $category = new stdClass();
                        $category->id = $row->id;
                        $category->application_id = $row->application_id;
                        $category->name = $row->name;
                        $category->alias = $row->alias;
                        $category->description = $row->description;
                        $category->parent = $row->parent;
                        $category->ordering = $row->ordering;
                        $category->published = $row->published;
                        $category->params = $row->params;
                        // Add category
                        $categoriesData[] = $category;
                    }
                }
            }
        }
        $categoryItems = $categoriesData; // Все категории с полями

        //отсортируем по уровню, чтобы не попадать в ситуацию, когда не существует родителя, чтобы не усложнять алгоритм на поиск предков
        usort($categoryItems, function ($a, $b) {
            return ($a->parent - $b->parent);
        });

        return (object)$categoryItems;
    }
}

