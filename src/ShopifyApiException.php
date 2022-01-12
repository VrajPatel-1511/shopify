<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Shopify;

class ShopifyApiException extends \Exception {

    protected $method;
    protected $path;
    protected $params;
    protected $response_headers;
    protected $response;

    function __construct($method, $path, $params, $response_headers, $response) {
        $this->method = $method;
        $this->path = $path;
        $this->params = $params;
        $this->response_headers = $response_headers;
        $this->response = $response;
        $error = "";
        if (!$response) {
            $error = "No Response from API";
        } else if (is_array($response) && isset($response['errors']) && (is_array($response['errors']) && ( count($response['errors']) > 0))) {
            $error = array_shift($response['errors']);
        } else {
            $error = $response['errors'];
        }

        if ((is_array($error) && ( count($error) > 0))) {
            $error = array_shift($error);
        }
        parent::__construct($error, $response_headers['http_status_code']);
    }

    function getMethod() {
        return $this->method;
    }

    function getPath() {
        return $this->path;
    }

    function getParams() {
        return $this->params;
    }

    function getResponseHeaders() {
        return $this->response_headers;
    }

    function getResponse() {
        return $this->response;
    }

}
