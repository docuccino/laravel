<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\ClaimController;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\SsoController;

/**
 * What the analyser answers for the component-name fixtures the locality rows route to: the two SSO
 * shapes, the request/response pair, the pinned pair, and a `Gizmo` it cannot expand beside one it
 * can. A support class rather than a helper in a test file, so the rows stay a line or two each and
 * nothing couples to a peer suite's globals.
 */
final class LocalityEngine
{
    public const SSO_INPUT = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Schema\\Authentication\\SSOConnectionData';

    public const SSO_OUTPUT = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Data\\SSO\\SSOConnectionData';

    public const PORTAL = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\PortalData';

    public const API_USER = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Api\\UserData';

    public const ADMIN_USER = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Admin\\UserData';

    public const BROKEN_GIZMO = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Broken\\Gizmo';

    public const WORKING_GIZMO = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Working\\Gizmo';

    public const BILLING_RECEIPT = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Billing\\ReceiptData';

    public const SUPPORT_RECEIPT = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Support\\ReceiptData';

    public static function make(): StubTypeEngine
    {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(
            returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))],
        );

        return WorkbenchEngine::make(
            classOverrides: [
                self::SSO_INPUT => new ClassMetadata(self::SSO_INPUT, [
                    new PropertyMetadata('issuerUrl', ScalarT::string()),
                    new PropertyMetadata('clientSecret', ScalarT::string()),
                ]),
                self::SSO_OUTPUT => new ClassMetadata(self::SSO_OUTPUT, [
                    new PropertyMetadata('issuerUrl', ScalarT::string()),
                    new PropertyMetadata('verified', ScalarT::bool()),
                ]),
                self::PORTAL => new ClassMetadata(self::PORTAL, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('name', ScalarT::string()),
                    new PropertyMetadata('token', ScalarT::string()),
                ]),
                self::API_USER => new ClassMetadata(self::API_USER, [new PropertyMetadata('handle', ScalarT::string())]),
                self::ADMIN_USER => new ClassMetadata(self::ADMIN_USER, [new PropertyMetadata('email', ScalarT::string())]),
                self::WORKING_GIZMO => new ClassMetadata(self::WORKING_GIZMO, [new PropertyMetadata('id', ScalarT::int())]),
                // The same members on both, so the two registrations are byte-equal.
                self::BILLING_RECEIPT => new ClassMetadata(self::BILLING_RECEIPT, [new PropertyMetadata('id', ScalarT::int())]),
                self::SUPPORT_RECEIPT => new ClassMetadata(self::SUPPORT_RECEIPT, [new PropertyMetadata('id', ScalarT::int())]),
                // Broken\Gizmo is deliberately absent: an unknown class is what the analyser giving
                // nothing back looks like from here.
            ],
            analysisOverrides: [
                SsoController::class.'::show' => $returns(self::SSO_OUTPUT),
                SsoController::class.'::unrelated' => $returns(self::SSO_INPUT),
                ClaimController::class.'::show' => $returns(self::PORTAL),
                ClaimController::class.'::apiUser' => $returns(self::API_USER),
                ClaimController::class.'::adminUser' => $returns(self::ADMIN_USER),
                ClaimController::class.'::brokenGizmo' => $returns(self::BROKEN_GIZMO),
                ClaimController::class.'::workingGizmo' => $returns(self::WORKING_GIZMO),
                ClaimController::class.'::billingReceipt' => $returns(self::BILLING_RECEIPT),
                ClaimController::class.'::supportReceipt' => $returns(self::SUPPORT_RECEIPT),
            ],
        );
    }

    /** @return callable(): TypeEngine */
    public static function factory(): callable
    {
        return static fn (): TypeEngine => self::make();
    }
}
