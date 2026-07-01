<?php

declare(strict_types=1);

namespace App;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Ogami ERP API",
 *     description="REST API for the Ogami ERP system — IATF 16949 certified plastic injection molding manufacturer",
 *     @OA\Contact(email="admin@ogami.ph")
 * )
 *
 * @OA\Server(
 *     url="/api/v1",
 *     description="Ogami ERP API v1"
 * )
 *
 * @OA\SecurityScheme(
 *     securitySchemeId="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     description="Laravel Sanctum session-based authentication. Obtain session via POST /auth/login."
 * )
 *
 * @OA\Tag(name="Authentication", description="Login, logout, session management")
 * @OA\Tag(name="Employees", description="HR employee management")
 * @OA\Tag(name="Work Orders", description="Production work order lifecycle")
 * @OA\Tag(name="Inspections", description="Quality inspection management")
 * @OA\Tag(name="Inventory", description="Inventory and stock management")
 * @OA\Tag(name="Purchasing", description="Purchase requests and orders")
 * @OA\Tag(name="SPC", description="Statistical Process Control")
 * @OA\Tag(name="KPI", description="KPI Scorecard")
 * @OA\Tag(name="Routings", description="Production routings and operations")
 */
class OpenApi {}
