<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\NotificationService;
use App\Services\SalaryService;
use App\Services\WorkScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected WorkScheduleService $schedules,
        protected SalaryService $salary,
        protected ExpenseService $expenses,
        protected NotificationService $notifications,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->string('name'),
                'email' => strtolower($request->string('email')),
                'password' => $request->string('password'),
            ]);
            $this->bootstrap($user, $request->string('name'));

            return $user;
        });

        $token = $user->createToken($request->input('device_name', 'mobile'))->plainTextToken;

        return $this->created([
            'token' => $token,
            'user' => new UserResource($user->load(['profile', 'salarySetting'])),
        ], 'Account created successfully.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', strtolower($request->string('email')))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $this->bootstrap($user, $user->name);
        $token = $user->createToken($request->input('device_name', 'mobile'))->plainTextToken;

        return $this->ok([
            'token' => $token,
            'user' => new UserResource($user->load(['profile', 'salarySetting'])),
        ], 'Logged in successfully.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(null, 'Logged out.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(new UserResource($request->user()->load(['profile', 'salarySetting'])));
    }

    /**
     * Make sure every account has its supporting rows (idempotent).
     */
    protected function bootstrap(User $user, string $name): void
    {
        $user->profile()->firstOrCreate([], ['full_name' => $name, 'timezone' => config('salary_tracker.timezone')]);
        $this->salary->settings($user);
        $this->notifications->settings($user);
        $this->schedules->ensureDefaults($user);
        $this->expenses->ensureDefaultCategories($user);
    }
}
