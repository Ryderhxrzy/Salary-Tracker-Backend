<?php

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\ResolvesApi;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\NotificationService;
use App\Services\SalaryService;
use App\Services\WalletService;
use App\Services\WorkScheduleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/** login / register / logout with Sanctum tokens, exactly like the REST auth endpoints. */
class AuthMutations
{
    use ResolvesApi;

    public function __construct(
        protected WorkScheduleService $schedules,
        protected SalaryService $salary,
        protected ExpenseService $expenses,
        protected NotificationService $notifications,
        protected WalletService $wallets,
    ) {}

    public function login($root, array $args): array
    {
        $input = $this->validate($args['input'], LoginRequest::rulesFor());
        $user = User::where('email', strtolower($input['email']))->first();

        if (! $user || ! Hash::check($input['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $this->bootstrap($user, $user->name);

        return $this->payload($user, $input['device_name'] ?? 'mobile');
    }

    public function register($root, array $args): array
    {
        $input = $this->validate($args['input'], RegisterRequest::rulesFor());

        $user = DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => strtolower($input['email']),
                'password' => $input['password'],
            ]);
            $this->bootstrap($user, $input['name']);

            return $user;
        });

        return $this->payload($user, $input['device_name'] ?? 'mobile');
    }

    public function logout($root, array $args, GraphQLContext $context): array
    {
        $this->user($context)->currentAccessToken()?->delete();

        return ['success' => true, 'message' => 'Logged out.'];
    }

    protected function payload(User $user, string $deviceName): array
    {
        return [
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => $this->normalize(new UserResource($user->load(['profile', 'salarySetting']))),
        ];
    }

    /** Make sure every account has its supporting rows (idempotent). */
    protected function bootstrap(User $user, string $name): void
    {
        $user->profile()->firstOrCreate([], ['full_name' => $name, 'timezone' => config('salary_tracker.timezone')]);
        $this->salary->settings($user);
        $this->notifications->settings($user);
        $this->schedules->ensureDefaults($user);
        $this->expenses->ensureDefaultCategories($user);
        $this->wallets->ensureDefaults($user);
    }
}
