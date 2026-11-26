<?php

/**
 * @file ReferenceCheckController.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ReferenceCheckController
 * @brief API endpoint: check the saved references of one publication.
 */

namespace APP\plugins\generic\referenceCheck;

use APP\core\Application;
use APP\plugins\generic\referenceCheck\classes\DoiLookup;
use APP\plugins\generic\referenceCheck\classes\OjsHttpFetcher;
use APP\plugins\generic\referenceCheck\classes\ReferenceChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\db\DAORegistry;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\PublicationAccessPolicy;
use PKP\security\authorization\UserRolesRequiredPolicy;
use PKP\security\Role;

class ReferenceCheckController extends PKPBaseController
{
    /** Seconds allowed for outside lookups; kept below common web server timeouts */
    private const TIME_BUDGET = 45;

    public function getHandlerPath(): string
    {
        return 'referenceCheck';
    }

    public function getRouteGroupMiddleware(): array
    {
        return [
            'has.user',
            'has.context',
            self::roleAuthorizer([
                Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SUB_EDITOR,
                Role::ROLE_ID_ASSISTANT,
            ]),
        ];
    }

    public function getGroupRoutes(): void
    {
        // POST, because the request causes outside lookups
        Route::post('{submissionId}/publications/{publicationId}', $this->check(...))
            ->name('referenceCheck.check')
            ->whereNumber(['submissionId', 'publicationId']);
    }

    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        $this->addPolicy(new UserRolesRequiredPolicy($request), true);
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        // Also requires access to the submission, and that it owns the publication
        $this->addPolicy(new PublicationAccessPolicy($request, $args, $roleAssignments));

        return parent::authorize($request, $args, $roleAssignments);
    }

    public function check(Request $illuminateRequest): JsonResponse
    {
        $publication = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_PUBLICATION);

        $references = DAORegistry::getDAO('CitationDAO')
            ->getRawCitationsByPublicationId($publication->getId())
            ->values()
            ->all();

        // Allow for the lookups where the server permits it
        if (function_exists('set_time_limit')) {
            set_time_limit(self::TIME_BUDGET + 30);
        }

        $checker = new ReferenceChecker(
            new DoiLookup(new OjsHttpFetcher(Application::get()->getHttpClient()), self::TIME_BUDGET)
        );

        return response()->json($checker->check($references), Response::HTTP_OK);
    }
}
