<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base Form Request for JSON API endpoints.
 *
 * Controllers must not validate request input inline. Extend this class
 * (or a more specific request) and put rules on the request object.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
