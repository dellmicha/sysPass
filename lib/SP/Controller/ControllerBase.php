<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      http://syspass.org
 * @copyright 2012-2017, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Controller;

defined('APP_ROOT') || die();

use Klein\Klein;
use Psr\Container\ContainerInterface;
use SP\Bootstrap;
use SP\Config\Config;
use SP\Config\ConfigData;
use SP\Core\Acl\Acl;
use SP\Core\Events\EventDispatcher;
use SP\Core\Exceptions\FileNotFoundException;
use SP\Core\Session\Session;
use SP\Core\UI\Theme;
use SP\Core\UI\ThemeIconsBase;
use SP\DataModel\ProfileData;
use SP\Http\JsonResponse;
use SP\Mvc\View\Template;
use SP\Services\User\UserLoginResponse;
use SP\Util\Checks;
use SP\Util\Json;
use SP\Util\Util;

/**
 * Clase base para los controladores
 */
abstract class ControllerBase
{
    /**
     * Constantes de errores
     */
    const ERR_UNAVAILABLE = 0;
    const ERR_ACCOUNT_NO_PERMISSION = 1;
    const ERR_PAGE_NO_PERMISSION = 2;
    const ERR_UPDATE_MPASS = 3;
    const ERR_OPERATION_NO_PERMISSION = 4;
    const ERR_EXCEPTION = 5;

    /**
     * @var Template Instancia del motor de plantillas a utilizar
     */
    protected $view;
    /**
     * @var  int ID de la acción
     */
    protected $action;
    /**
     * @var string Nombre de la acción
     */
    protected $actionName;
    /**
     * @var ThemeIconsBase Instancia de los iconos del tema visual
     */
    protected $icons;
    /**
     * @var string Nombre del controlador
     */
    protected $controllerName;
    /**
     * @var  UserLoginResponse
     */
    protected $userData;
    /**
     * @var  ProfileData
     */
    protected $userProfileData;
    /**
     * @var  EventDispatcher
     */
    protected $eventDispatcher;
    /**
     * @var bool
     */
    protected $loggedIn = false;
    /**
     * @var  ConfigData
     */
    protected $configData;
    /**
     * @var  Config
     */
    protected $config;
    /**
     * @var  Session
     */
    protected $session;
    /**
     * @var  Theme
     */
    protected $theme;
    /**
     * @var  \SP\Core\Acl\Acl
     */
    protected $acl;
    /**
     * @var Klein
     */
    protected $router;
    /**
     * @var ContainerInterface
     */
    protected $dic;

    /**
     * Constructor
     *
     * @param $actionName
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function __construct($actionName)
    {
        $this->dic = Bootstrap::getContainer();

        $class = static::class;
        $this->controllerName = substr($class, strrpos($class, '\\') + 1, -strlen('Controller'));
        $this->actionName = $actionName;

        $this->config = $this->dic->get(Config::class);
        $this->configData = $this->config->getConfigData();
        $this->session = $this->dic->get(Session::class);
        $this->theme = $this->dic->get(Theme::class);
        $this->eventDispatcher = $this->dic->get(EventDispatcher::class);
        $this->acl = $this->dic->get(Acl::class);
        $this->router = $this->dic->get(Klein::class);
        $this->view = $this->dic->get(Template::class);

        $this->view->setBase(strtolower($this->controllerName));

        $this->icons = $this->theme->getIcons();

        $this->setViewVars();

        if (method_exists($this, 'initialize')) {
            $this->initialize();
        }
    }

    /**
     * Set view variables
     */
    private function setViewVars()
    {
        $this->userData = $this->session->getUserData();
        $this->userProfileData = $this->session->getUserProfile();

        $this->view->assign('timeStart', $_SERVER['REQUEST_TIME_FLOAT']);
        $this->view->assign('icons', $this->icons);
        $this->view->assign('SessionUserData', $this->userData);
        $this->view->assign('queryTimeStart', microtime());
        $this->view->assign('userId', $this->userData->getId());
        $this->view->assign('userGroupId', $this->userData->getUserGroupId());
        $this->view->assign('userIsAdminApp', $this->userData->getIsAdminApp());
        $this->view->assign('userIsAdminAcc', $this->userData->getIsAdminAcc());
        $this->view->assign('themeUri', $this->view->getTheme()->getThemeUri());
        $this->view->assign('isDemo', $this->configData->isDemoEnabled());
    }

    /**
     * Mostrar los datos de la plantilla
     */
    public function view()
    {
        try {
            echo $this->view->render();
        } catch (FileNotFoundException $e) {
            debugLog($e->getMessage(), true);

            echo $e->getMessage();
        }

        die();
    }

    /**
     * Renderizar los datos de la plantilla y devolverlos
     */
    public function render()
    {
        try {
            return $this->view->render();
        } catch (FileNotFoundException $e) {
            debugLog($e->getMessage(), true);

            return $e->getMessage();
        }
    }

    /**
     * Obtener los datos para la vista de depuración
     */
    public function getDebug()
    {
        global $memInit;

        $this->view->addTemplate('debug', 'common');

        $this->view->assign('time', getElapsedTime());
        $this->view->assign('memInit', $memInit / 1000);
        $this->view->assign('memEnd', memory_get_usage() / 1000);
    }

    /**
     * Comprobar si el usuario está logado.
     */
    public function checkLoggedIn()
    {
        if (!$this->session->isLoggedIn()) {
            if (Checks::isJson()) {
                $jsonResponse = new JsonResponse();
                $jsonResponse->setDescription(__u('La sesión no se ha iniciado o ha caducado'));
                $jsonResponse->setStatus(10);

                Json::returnJson($jsonResponse);
            } else {
                Util::logout();
            }
        }
    }

    /**
     * @param bool $loggedIn
     */
    protected function setLoggedIn($loggedIn)
    {
        $this->loggedIn = (bool)$loggedIn;
        $this->view->assign('loggedIn', $this->loggedIn);
    }

    /**
     * Comprobar si está permitido el acceso al módulo/página.
     *
     * @param null $action La acción a comprobar
     * @return bool
     */
    protected function checkAccess($action)
    {
        return $this->session->getUserData()->getIsAdminApp() || $this->acl->checkUserAccess($action);
    }
}