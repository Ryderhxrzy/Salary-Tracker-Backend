<?php

namespace App\GraphQL\Support;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Helpers shared by the GraphQL resolvers. Everything goes through the same
 * services, resources and validation rules as the REST controllers, so both
 * APIs always answer the same thing.
 */
trait ResolvesApi
{
    protected function user(GraphQLContext $context): User
    {
        /** @var User $user */
        $user = $context->user();

        return $user;
    }

    /** Validate mutation input with a form request's rules (throws a GraphQL validation error). */
    protected function validate(array $input, array $rules, array $after = []): array
    {
        $validator = Validator::make($input, $rules);
        foreach ($after as $check) {
            $validator->after($check);
        }

        return $validator->validate();
    }

    /** Resources / nested resources -> plain arrays the GraphQL type system can walk. */
    protected function normalize(mixed $data): mixed
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function authorize(string $ability, Model $model): void
    {
        Gate::authorize($ability, $model);
    }

    /** Owned row by id or a client-safe "not found" error (never another user's row). */
    protected function owned(User $user, string $relation, int $id): Model
    {
        return $user->{$relation}()->find($id) ?? throw ApiException::notFound();
    }

    /** @return array{range: string, from: ?string, to: ?string} */
    protected function rangeArgs(array $args): array
    {
        return [
            'range' => $args['range'] ?? 'period',
            'from' => $args['from'] ?? null,
            'to' => $args['to'] ?? null,
        ];
    }
}
