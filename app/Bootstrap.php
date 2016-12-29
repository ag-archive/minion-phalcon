<?php

namespace MinionCMS;

/**
 * Bootstrap
 * @author Dam Viet <damviet112@gmail.com>
 */
class Bootstrap
{

    public function run()
    {
        $di = new \Phalcon\DI\FactoryDefault();

        // Config
        require_once APPLICATION_PATH . '/app/modules/Cms/Config.php';
        $config = \Cms\Config::get();
        $di->set('config', $config);

        // Registry
        $registry = new \Phalcon\Registry();
        $di->set('registry', $registry);

        // Loader
        $loader = new \Phalcon\Loader();
        $loader->registerNamespaces($config->loader->namespaces->toArray());
        $loader->registerDirs([APPLICATION_PATH . "/app/plugins/"]);
        $loader->register();
        require_once APPLICATION_PATH . '/vendor/autoload.php';

        // Database
        $db = new \Phalcon\Db\Adapter\Pdo\Mysql([
            "host"     => $config->database->host,
            "username" => $config->database->username,
            "password" => $config->database->password,
            "dbname"   => $config->database->dbname,
            "charset"  => $config->database->charset,
        ]);
        $di->set('db', $db);

        // View
        $this->initView($di);

        // URL
        $url = new \Phalcon\Mvc\Url();
        $url->setBasePath($config->base_path);
        $url->setBaseUri($config->base_path);
        $di->set('url', $url);

        // Cache
        $this->initCache($di);

        // CMS
        $cmsModel = new \Models\Cms\Configuration();
        $registry->cms = $cmsModel->getConfig();

        // Application
        $application = new \Phalcon\Mvc\Application();
        $application->registerModules($config->modules->toArray());

        // Events Manager, Dispatcher
        $this->initEventManager($di);

        // Session
        $session = new \Phalcon\Session\Adapter\Files();
        $session->start();
        $di->set('session', $session);

        $acl = new \Application\Acl\DefaultAcl();
        $di->set('acl', $acl);

        // Flash helper
        $flash = new \Phalcon\Flash\Session([
            'error'   => 'callout callout-danger',
            'success' => 'callout callout-info',
            'notice'  => 'box box-default',
            'warning' => 'callout callout-info',
        ]);
        $di->set('flash', $flash);

        $di->set('helper', new \Application\Mvc\Helper());

        // Routing
        $this->initRouting($di);

        $application->setDI($di);

        // Main dispatching process
        $this->dispatch($di);

    }

    private function initRouting($di)
    {
        $di->set('router', function () {
            return require APPLICATION_PATH . '/routes/web.php';
        });
    }

    private function initEventManager($di)
    {
        $eventsManager = new \Phalcon\Events\Manager();
        $dispatcher = new \Phalcon\Mvc\Dispatcher();

        $eventsManager->attach("dispatch:beforeDispatchLoop", function ($event, $dispatcher) use ($di) {
            new \MinionCMS\Plugin\CheckPoint($di->get('request'));
            new \MinionCMS\Plugin\Localization($dispatcher);
            new \MinionCMS\Plugin\AdminLocalization($di->get('config'));
            new \MinionCMS\Plugin\Acl($di->get('acl'), $dispatcher, $di->get('view'));
        });

        $eventsManager->attach("dispatch:afterDispatchLoop", function ($event, $dispatcher) use ($di) {
            new \MinionCMS\Plugin\Title($di);
        });

        // Profiler
        $registry = $di->get('registry');
        if ($registry->cms['PROFILER']) {
            $profiler = new \Phalcon\Db\Profiler();
            $di->set('profiler', $profiler);

            $eventsManager->attach('db', function ($event, $db) use ($profiler) {
                if ($event->getType() == 'beforeQuery') {
                    $profiler->startProfile($db->getSQLStatement());
                }
                if ($event->getType() == 'afterQuery') {
                    $profiler->stopProfile();
                }
            });
        }

        $db = $di->get('db');
        $db->setEventsManager($eventsManager);

        $dispatcher->setEventsManager($eventsManager);
        $di->set('dispatcher', $dispatcher);
    }

    private function initView($di)
    {
        $view = new \Phalcon\Mvc\View();

        define('MAIN_VIEW_PATH', APPLICATION_PATH . '/resources/volt/');
        $view->setMainView(MAIN_VIEW_PATH . 'main');
        $view->setLayoutsDir(MAIN_VIEW_PATH . '/layouts/');
        $view->setLayout('main');
        $view->setPartialsDir(MAIN_VIEW_PATH . '/partials/');

        // Volt
        $volt = new \Application\Mvc\View\Engine\Volt($view, $di);
        $volt->setOptions(['compiledPath' => APPLICATION_PATH . '/storage/framework/volt/']);
        $volt->initCompiler();


        $phtml = new \Phalcon\Mvc\View\Engine\Php($view, $di);
        $viewEngines = [
            ".volt"  => $volt,
            ".phtml" => $phtml,
        ];

        $view->registerEngines($viewEngines);

        $ajax = $di->get('request')->getQuery('_ajax');
        if ($ajax) {
            $view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_LAYOUT);
        }

        $di->set('view', $view);

        return $view;
    }

    private function initCache($di)
    {
        $config = $di->get('config');

        $cacheFrontend = new \Phalcon\Cache\Frontend\Data([
            "lifetime" => 60,
            "prefix"   => HOST_HASH,
        ]);

        $cache = null;
        switch ($config->cache) {
            case 'file':
                $cache = new \Phalcon\Cache\Backend\File($cacheFrontend, [
                    "cacheDir" => APPLICATION_PATH . "/storage/framework/cache/backend/"
                ]);
                break;
            case 'redis':
                $cache = new \Phalcon\Cache\Backend\Redis(
                    $cacheFrontend, [
                    "host" => $config->redis->host,
                    "port" => $config->redis->port,
                ]);
                break;
        }
        $di->set('cache', $cache, true);
        $di->set('modelsCache', $cache, true);

        \Application\Widget\Proxy::$cache = $cache; // Modules Widget System

        $modelsMetadata = new \Phalcon\Mvc\Model\Metadata\Memory();
        $di->set('modelsMetadata', $modelsMetadata);

        $cacheManager = new \Application\Cache\Manager();
        $di->set('cacheManager', $cacheManager);

    }

    private function dispatch($di)
    {
        $router = $di['router'];

        $router->handle();

        $view = $di['view'];

        $dispatcher = $di['dispatcher'];

        $response = $di['response'];

        $dispatcher->setModuleName($router->getModuleName());
        $dispatcher->setControllerName($router->getControllerName());
        $dispatcher->setActionName($router->getActionName());
        $dispatcher->setParams($router->getParams());

        $moduleName = \Application\Utils\ModuleName::camelize($router->getModuleName());

        $ModuleClassName = $moduleName . '\Module';
        if (class_exists($ModuleClassName)) {
            $module = new $ModuleClassName;
            $module->registerAutoloaders();
            $module->registerServices($di);
        }

        $view->start();

        $registry = $di['registry'];
        if ($registry->cms['DEBUG_MODE']) {
            $debug = new \Phalcon\Debug();
            $debug->listen();

            $dispatcher->dispatch();
        } else {
            try {
                $dispatcher->dispatch();
            } catch (\Phalcon\Exception $e) {
                // Errors catching

                $view->setViewsDir(APPLICATION_PATH . '/resources/volt/index');
                $view->setPartialsDir('');
                $view->e = $e;

                if ($e instanceof \Phalcon\Mvc\Dispatcher\Exception) {
                    $response->setHeader(404, 'Not Found');
                    $view->partial('error/error404');
                } else {
                    $response->setHeader(503, 'Service Unavailable');
                    $view->partial('error/error503');
                }
                $response->sendHeaders();
                echo $response->getContent();
                return;

            }
        }

        $view->render(
            $dispatcher->getControllerName(),
            $dispatcher->getActionName(),
            $dispatcher->getParams()
        );

        $view->finish();

        $response = $di['response'];

        // AJAX
        $request = $di['request'];
        $_ajax = $request->getQuery('_ajax');
        if ($_ajax) {
            $contents = $view->getContent();

            $return = new \stdClass();
            $return->html = $contents;
            $return->title = $di->get('helper')->title()->get();
            $return->success = true;

            $headers = $response->getHeaders()->toArray();
            if (isset($headers[404]) || isset($headers[503])) {
                $return->success = false;
            }
            $response->setContentType('application/json', 'UTF-8');
            $response->setContent(json_encode($return));
        } else {
            $response->setContent($view->getContent());
        }

        $response->sendHeaders();

        echo $response->getContent();
    }

}
