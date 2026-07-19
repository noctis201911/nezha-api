<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Vendor\VendorController;
use Tests\TestCase;

final class UpdateProfileStandaloneJsonResponse
{
    public function __construct(
        public readonly array $data,
        public readonly int $status,
    ) {}
}

final class UpdateProfileStandaloneResponseFactory
{
    public function json(array $data, int $status = 200): UpdateProfileStandaloneJsonResponse
    {
        return new UpdateProfileStandaloneJsonResponse($data, $status);
    }
}

final class VendorUpdateProfileAuthorizationContract
{
    private const CONTROLLER = 'app/Http/Controllers/Api/V1/Vendor/VendorController.php';

    private const ROUTES = 'routes/api/v1/api.php';

    private const MIDDLEWARE = 'app/Http/Middleware/VendorTokenIsValid.php';

    /**
     * @return array<string, list<array{passed: bool, message: string}>>
     */
    public static function checks(string $root): array
    {
        return [
            'employee actor boundary' => self::employeeActorBoundaryChecks($root),
            'isolated employee behavior' => self::isolatedEmployeeBehaviorChecks($root),
            'owner profile continuity' => self::ownerProfileContinuityChecks($root),
            'route and realm boundary' => self::routeAndRealmBoundaryChecks($root),
        ];
    }

    /**
     * @return list<array{passed: bool, message: string}>
     */
    private static function employeeActorBoundaryChecks(string $root): array
    {
        $method = self::compact(self::methodSource(self::read($root, self::CONTROLLER), 'update_profile'));
        $bodyStart = strpos($method, '{');
        if ($bodyStart === false) {
            throw new \RuntimeException('update_profile() has no method body');
        }

        $body = substr($method, $bodyStart + 1, -1);
        $guard = "if(\$request['vendor_employee']){returnresponse()->json(['errors'=>[['code'=>'auth-001','message'=>'Unauthorized.']]],403);}";
        $guardPosition = strpos($body, $guard);
        $guardEnd = $guardPosition === false ? -1 : $guardPosition + strlen($guard);
        $checks = [];

        self::add(
            $checks,
            str_starts_with($body, $guard),
            "update_profile() must start with the authenticated request['vendor_employee'] auth-001/Unauthorized. HTTP 403 guard"
        );
        self::add(
            $checks,
            ! str_contains($body, 'vendorType'),
            'update_profile() must not authorize from the raw vendorType header'
        );

        $targets = [
            "\$vendor=\$request['vendor'];",
            'Validator::make($request->all(),[',
            "'phone'=>'required|unique:vendors,phone,'.\$vendor->id,",
            "if(\$request->has('image')){",
            "Helpers::update(dir:'vendor/',old_image:\$vendor->image,format:'png',image:\$request->file('image'));",
            "if(\$request['password']!=null){",
            "\$pass=bcrypt(\$request['password']);",
            '$vendor->f_name=$request->f_name;',
            '$vendor->l_name=$request->l_name;',
            '$vendor->phone=$request->phone;',
            '$vendor->image=$imageName;',
            '$vendor->password=$pass;',
            '$vendor->updated_at=now();',
            '$vendor->save();',
        ];

        foreach ($targets as $target) {
            $targetPosition = strpos($body, $target);
            self::add(
                $checks,
                $guardPosition === 0 && $targetPosition !== false && $targetPosition >= $guardEnd,
                "employee 403 guard must precede update-profile target operation: {$target}"
            );
        }

        return $checks;
    }

    /**
     * Invoke only the employee branch without a Laravel bootstrap. The request
     * double throws on every offset except the middleware-injected actor, so an
     * employee response cannot read tenant-owner data or payload fields.
     *
     * @return list<array{passed: bool, message: string}>
     */
    private static function isolatedEmployeeBehaviorChecks(string $root): array
    {
        self::installStandaloneControllerDependencies();

        $controllerPath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::CONTROLLER);
        if (! class_exists(VendorController::class, false)) {
            require_once $controllerPath;
        }

        $request = new class extends \Illuminate\Http\Request
        {
            /** @var list<string> */
            public array $accessedOffsets = [];

            public function offsetGet($key): mixed
            {
                $this->accessedOffsets[] = (string) $key;
                if ($key !== 'vendor_employee') {
                    throw new \RuntimeException("update_profile() crossed into protected request offset: {$key}");
                }

                return parent::offsetGet($key);
            }
        };
        $request['vendor_employee'] = 7002;

        $checks = [];
        try {
            $response = (new VendorController)->update_profile($request);
            $status = $response instanceof UpdateProfileStandaloneJsonResponse ? $response->status : $response->getStatusCode();
            $data = $response instanceof UpdateProfileStandaloneJsonResponse ? $response->data : $response->getData(true);

            self::add($checks, $status === 403, 'authenticated employee must receive exact HTTP 403');
            self::add(
                $checks,
                $data === ['errors' => [['code' => 'auth-001', 'message' => 'Unauthorized.']]],
                'authenticated employee must receive exact auth-001/Unauthorized. JSON'
            );
        } catch (\Throwable $exception) {
            self::add($checks, false, 'authenticated employee controller invocation failed: '.$exception->getMessage());
            self::add($checks, false, 'authenticated employee response payload was not produced');
        }

        self::add(
            $checks,
            $request->accessedOffsets === ['vendor_employee'],
            'employee request must return before vendor, phone, image, password, field, timestamp, save, or method-specific disclosure access'
        );

        return $checks;
    }

    private static function installStandaloneControllerDependencies(): void
    {
        if (! class_exists(\App\Http\Controllers\Controller::class)) {
            eval('namespace App\\Http\\Controllers; class Controller {}');
        }

        if (! class_exists(\Illuminate\Http\Request::class)) {
            eval(<<<'PHP'
namespace Illuminate\Http;
class Request implements \ArrayAccess
{
    private array $items = [];
    public function offsetExists(mixed $offset): bool { return array_key_exists((string) $offset, $this->items); }
    public function offsetGet(mixed $offset): mixed { return $this->items[(string) $offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->items[(string) $offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->items[(string) $offset]); }
}
PHP);
        }

        if (! function_exists('response')) {
            eval('namespace { function response() { return new \\Tests\\Feature\\UpdateProfileStandaloneResponseFactory(); } }');
        }
    }

    /**
     * @return list<array{passed: bool, message: string}>
     */
    private static function ownerProfileContinuityChecks(string $root): array
    {
        $method = self::compact(self::methodSource(self::read($root, self::CONTROLLER), 'update_profile'));
        $checks = [];
        $orderedOperations = [
            "\$vendor=\$request['vendor'];",
            'Validator::make($request->all(),[',
            "'f_name'=>'required',",
            "'l_name'=>'required',",
            "'phone'=>'required|unique:vendors,phone,'.\$vendor->id,",
            "'password'=>['nullable',Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],",
            "'image'=>'nullable|max:2048',",
            "if(\$validator->fails()){returnresponse()->json(['errors'=>Helpers::error_processor(\$validator)],403);}",
            "if(\$request->has('image')){",
            "\$imageName=Helpers::update(dir:'vendor/',old_image:\$vendor->image,format:'png',image:\$request->file('image'));",
            "if(\$request['password']!=null){\$pass=bcrypt(\$request['password']);}else{\$pass=\$vendor->password;}",
            '$vendor->f_name=$request->f_name;',
            '$vendor->l_name=$request->l_name;',
            '$vendor->phone=$request->phone;',
            '$vendor->image=$imageName;',
            '$vendor->password=$pass;',
            '$vendor->updated_at=now();',
            '$vendor->save();',
            "returnresponse()->json(['message'=>translate('messages.profile_updated_successfully')],200);",
        ];

        $previous = -1;
        foreach ($orderedOperations as $operation) {
            $position = strpos($method, $operation);
            self::add(
                $checks,
                $position !== false && $position > $previous,
                "owner profile validation or mutation is missing or out of order: {$operation}"
            );
            if ($position !== false) {
                $previous = $position;
            }
        }

        return $checks;
    }

    /**
     * @return list<array{passed: bool, message: string}>
     */
    private static function routeAndRealmBoundaryChecks(string $root): array
    {
        $routeSource = self::read($root, self::ROUTES);
        $middlewareSource = self::read($root, self::MIDDLEWARE);
        $routes = self::compact($routeSource);
        $middleware = self::compact($middlewareSource);
        $checks = [];

        self::add(
            $checks,
            self::gitBlobId($routeSource) === 'b93c9a4587d2f7b2f26a2938305e453649f2aa0d',
            'candidate route file must remain byte-for-byte unchanged'
        );
        self::add(
            $checks,
            self::gitBlobId($middlewareSource) === '3310be478827726f37043d17c4b1f2f0a51bf13d',
            'VendorTokenIsValid middleware must remain byte-for-byte unchanged'
        );

        $vendorGroup = "Route::group(['prefix'=>'vendor','namespace'=>'Vendor','middleware'=>['vendor.api','actch:restaurant_app']],function(){";
        $updateRoute = "Route::put('update-profile',[VendorController::class,'update_profile']);";
        $groupPosition = strpos($routes, $vendorGroup);
        $routePosition = strpos($routes, $updateRoute);
        self::add(
            $checks,
            $groupPosition !== false && $routePosition !== false && $groupPosition < $routePosition,
            'PUT api/v1/vendor/update-profile must remain inside the vendor.api + actch:restaurant_app vendor group'
        );

        $missingHeader = "if(!\$request->hasHeader('vendorType')||!in_array(\$request->header('vendorType'),['owner','employee'],true)){\$errors=[];array_push(\$errors,['code'=>'vendor_type','message'=>translate('messages.vendor_type_required')]);returnresponse()->json(['errors'=>\$errors],403);}";
        $ownerRealm = "if(\$vendor_type=='owner'){\$vendor=Vendor::where('auth_token',\$token)->first();";
        $employeeRealm = "elseif(\$vendor_type=='employee'){\$vendor=VendorEmployee::where('auth_token',\$token)->where('status',1)->first();";
        $employeeInjection = "\$request['vendor']=\$vendor->vendor;\$request['vendor_employee']=\$vendor;";

        self::add($checks, str_contains($middleware, $missingHeader), 'missing or invalid vendorType must retain the existing HTTP 403 rejection');
        self::add($checks, str_contains($middleware, $ownerRealm), 'owner header must resolve bearer tokens only through Vendor');
        self::add($checks, str_contains($middleware, $employeeRealm), 'employee header must resolve bearer tokens only through VendorEmployee');
        self::add($checks, str_contains($middleware, $employeeInjection), 'employee authentication must inject tenant owner and authenticated vendor_employee actor');
        self::add(
            $checks,
            substr_count($middleware, "\$request['vendor_employee']=") === 1
                && substr_count($middleware, "\$request['vendor']=") === 2,
            'owner-token/employee-header and employee-token/owner-header mismatches have no alternate authenticated actor injection path'
        );

        return $checks;
    }

    /**
     * @param  list<array{passed: bool, message: string}>  $checks
     */
    private static function add(array &$checks, bool $passed, string $message): void
    {
        $checks[] = ['passed' => $passed, 'message' => $message];
    }

    private static function read(string $root, string $relativePath): string
    {
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Unable to read {$relativePath}");
        }

        return $source;
    }

    private static function methodSource(string $source, string $method): string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $index + 1;
            while ($nameIndex < $count) {
                $token = $tokens[$nameIndex];
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $nameIndex++;

                    continue;
                }
                if ($token === '&') {
                    $nameIndex++;

                    continue;
                }
                break;
            }

            if ($nameIndex >= $count || ! is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING || $tokens[$nameIndex][1] !== $method) {
                continue;
            }

            $sourceTokens = [];
            $depth = 0;
            $opened = false;
            for ($cursor = $index; $cursor < $count; $cursor++) {
                $token = $tokens[$cursor];
                $text = is_array($token) ? $token[1] : $token;
                $sourceTokens[] = $text;

                if ($text === '{') {
                    $opened = true;
                    $depth++;
                } elseif ($text === '}' && $opened) {
                    $depth--;
                    if ($depth === 0) {
                        return implode('', $sourceTokens);
                    }
                }
            }
        }

        throw new \RuntimeException("Unable to find {$method}()");
    }

    private static function compact(string $source): string
    {
        $result = '';
        foreach (token_get_all("<?php\n".$source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $result .= $token[1];
            } else {
                $result .= $token;
            }
        }

        return $result;
    }

    private static function gitBlobId(string $source): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $source);

        return sha1('blob '.strlen($normalized)."\0".$normalized);
    }
}

if (class_exists(TestCase::class)) {
    final class NezhaVendorUpdateProfileAuthorizationTest extends TestCase
    {
        public function test_update_profile_authorization_contract(): void
        {
            foreach (VendorUpdateProfileAuthorizationContract::checks(dirname(__DIR__, 2)) as $group => $checks) {
                foreach ($checks as $check) {
                    $this->assertTrue($check['passed'], "{$group}: {$check['message']}");
                }
            }
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $groups = VendorUpdateProfileAuthorizationContract::checks(dirname(__DIR__, 2));
    $tests = count($groups);
    $assertions = 0;
    $failures = [];

    foreach ($groups as $group => $checks) {
        foreach ($checks as $check) {
            $assertions++;
            if (! $check['passed']) {
                $failures[] = "{$group}: {$check['message']}";
            }
        }
    }

    if ($failures !== []) {
        fwrite(STDERR, "FAIL: {$tests} tests, {$assertions} assertions, ".count($failures)." failures\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, "- {$failure}\n");
        }
        exit(1);
    }

    fwrite(STDOUT, "PASS: {$tests} tests, {$assertions} assertions\n");
}
