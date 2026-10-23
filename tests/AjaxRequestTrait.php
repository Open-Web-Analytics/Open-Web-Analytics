<?php

/**
 * Drives a Core\Controller\Ajax endpoint as index.php would: web_app mode, a
 * request method, the controller's doAction(), and the rendered body.
 *
 * Restores the request mode and method afterwards; RestControllerTestCase runs
 * everything else in rest_api mode.
 */
trait AjaxRequestTrait
{
    /**
     * @return array status, json (decoded body), ran (the controller's data)
     */
    protected function ajax($controller, string $method = 'POST'): array
    {
        $request = \OWA\Core\CoreAPI::serviceSingleton()->request;
        $mode    = \OWA\Core\CoreAPI::getSetting('base', 'request_mode');
        $type    = $request->request_type;

        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'web_app');
        $request->request_type = $method;
        http_response_code(200);

        try {
            $data = (array) $controller->doAction();

            ob_start();
            $returned = \OWA\Core\CoreAPI::displayView($data);
            $printed  = ob_get_clean();

            return array(
                'status' => http_response_code(),
                'json'   => json_decode((string) ($returned ?: $printed), true),
                'data'   => $data,
            );
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'request_mode', $mode);
            $request->request_type = $type;
        }
    }

    /** As though authenticated by $method earlier in this request; null for nobody. */
    protected function signedInBy(?string $method): void
    {
        $auth = \OWA\Core\Auth::get_instance();

        foreach (array('auth_method' => $method, '_is_user' => false) as $name => $value) {
            $p = new \ReflectionProperty($auth, $name);
            $p->setAccessible(true);
            $p->setValue($auth, $value);
        }
    }

    /** A nonce for this action and the current user. */
    protected static function nonceFor(string $action): string
    {
        return \OWA\Core\CoreAPI::createNonce($action);
    }
}
