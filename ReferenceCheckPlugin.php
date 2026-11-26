<?php

/**
 * @file ReferenceCheckPlugin.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ReferenceCheckPlugin
 *
 * @brief Lets editors check a reference list for retracted, corrected and unresolvable work.
 */

namespace APP\plugins\generic\referenceCheck;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\core\APIRouter;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class ReferenceCheckPlugin extends GenericPlugin
{
    /** @copydoc GenericPlugin::register() */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if (!$success || Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        // Plugin API endpoints arrived in OJS 3.5.0-4. Without them, show nothing.
        // (Analysis of a newer release sees only that the method exists.)
        // @phpstan-ignore function.alreadyNarrowedType
        if (!method_exists(APIRouter::class, 'registerPluginApiControllers')) {
            return $success;
        }

        Hook::add('APIHandler::endpoints::plugin', function (string $hookName, APIRouter $apiRouter): bool {
            $apiRouter->registerPluginApiControllers([new ReferenceCheckController()]);
            return Hook::CONTINUE;
        });

        $request = Application::get()->getRequest();
        $templateManager = TemplateManager::getManager($request);
        $assets = "{$request->getBaseUrl()}/{$this->getPluginPath()}/public";
        $templateManager->addJavaScript('referenceCheck', "{$assets}/js/referenceCheck.js", [
            'contexts' => ['backend'],
            'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
        ]);
        $templateManager->addStyleSheet('referenceCheck', "{$assets}/css/referenceCheck.css", [
            'contexts' => ['backend'],
        ]);

        return $success;
    }

    /** @copydoc Plugin::getDisplayName() */
    public function getDisplayName(): string
    {
        return __('plugins.generic.referenceCheck.displayName');
    }

    /** @copydoc Plugin::getDescription() */
    public function getDescription(): string
    {
        return __('plugins.generic.referenceCheck.description');
    }
}
