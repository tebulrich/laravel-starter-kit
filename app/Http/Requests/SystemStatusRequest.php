<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Example list/filter request for the sample status endpoint.
 */
final class SystemStatusRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'include' => ['sometimes', 'string', 'in:version,queue'],
        ];
    }

    public function include(): ?string
    {
        $include = $this->validated('include');

        return is_string($include) === true ? $include : null;
    }
}
