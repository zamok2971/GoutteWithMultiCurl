<?php
namespace Goutte;
use Goutte\Client as GoutteClient;
use Symfony\Component\BrowserKit\Client as BaseClient;
use Symfony\Component\BrowserKit\History;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;

use Zend\Http\Client as ZendClient;
use Zend\Http\Response as ZendResponse;

/*
 * This file is part of the Goutte package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Client.
 *
 * @package Goutte
 * @author  Fabien Potencier <fabien.potencier@symfony-project.com>
 */
class MultiClient extends ZendClient
{
    protected $mcurl = null;

    protected $curl = array();

    // protected $response = array();

    /**
     * Initialize curl
     *
     * @param  array  $request - параметры запроса
     * @param  int     $request['port']
     * @param  boolean $request['secure']
     * @return void
     * @throws \Zend\Http\Client\Adapter\Exception if unable to connect
     */
    public function execute($requests)
    {
        // $mem_usage_start = memory_get_usage(true);
        // If we're already connected, disconnect first
        if ($this->mcurl) {
            foreach ($this->curl as $key => $curl) {
                if ($curl) {
                    $this->cclose($curl, $key);
                }
            }
            $this->mclose();
        }

        // If we are connected to a different server or port, disconnect first
        if ($this->mcurl
            && is_array($this->connectedTo)
            && ($this->connectedTo[0] != $requests['host'][0]
                || $this->connectedTo[1] != $requests['port'][0])
        ) {
            $this->mclose();
        }

        // Do the actual connection
        $this->mcurl = curl_multi_init();
        foreach ($requests as $k => &$request) {

            $request_params = array('parameters' => array(), 'files' => array(), 'server' => array(), 'content' => null, 'changeHistory' => true);
            foreach ($request_params as $kpar => $param) {
                if (!isset($request[$kpar])) {
                    $request[$kpar] = $param;
                }
            }

            // cookies
            $secure = false; // https или http
            $cookie = $this->prepareCookies($request['host'], $request['path'], $secure);
            if ($cookie->getFieldValue()) {
                $request['headers']['Cookie'] = $cookie->getFieldValue();
            }

            $uri = $request['uri'];
            $this->curl[$k] = curl_init($uri);

            // Set timeout
            curl_setopt($this->curl[$k], CURLOPT_CONNECTTIMEOUT, $this->config['timeout']);
            // Set Max redirects
            curl_setopt($this->curl[$k], CURLOPT_MAXREDIRS, $this->config['maxredirects']);
            if (!$this->curl[$k]) {
                $this->close($this->curl[$k]);

                throw new AdapterException\RuntimeException('Unable to Connect to ' . $request['host'] . ':' . $request['port']);
            }

            // Update connected_to
            //$this->connectedTo[$uri] = array($request['host'], $request['port']);

            // ensure correct curl call
            $curlValue = true;
            switch ($request['method']) {
                case 'GET' :
                    $curlMethod = CURLOPT_HTTPGET;
                    break;

                case 'POST' :
                    $curlMethod = CURLOPT_POST;
                    break;

                case 'PUT' :
                    // There are two different types of PUT request, either a Raw Data string has been set
                    // or CURLOPT_INFILE and CURLOPT_INFILESIZE are used.
                    if (is_resource($request['body'])) {
                        $this->config['curloptions'][CURLOPT_INFILE] = $request['body'];
                    }
                    if (isset($this->config['curloptions'][CURLOPT_INFILE])) {
                        // Now we will probably already have Content-Length set, so that we have to delete it
                        // from $headers at this point:
                        foreach ($request['headers'] AS $k => $header) {
                            if (preg_match('/Content-Length:\s*(\d+)/i', $header, $m)) {
                                if (is_resource($request['body'])) {
                                    $this->config['curloptions'][CURLOPT_INFILESIZE] = (int) $m[1];
                                }
                                unset($request['headers'][$k]);
                            }
                        }

                        if (!isset($this->config['curloptions'][CURLOPT_INFILESIZE])) {
                            throw new AdapterException\RuntimeException("Cannot set a file-handle for cURL option CURLOPT_INFILE without also setting its size in CURLOPT_INFILESIZE.");
                        }

                        if (is_resource($request['body'])) {
                            $request['body'] = '';
                        }

                        $curlMethod = CURLOPT_PUT;
                    } else {
                        $curlMethod = CURLOPT_CUSTOMREQUEST;
                        $curlValue = "PUT";
                    }
                    break;

                case 'DELETE' :
                    $curlMethod = CURLOPT_CUSTOMREQUEST;
                    $curlValue = "DELETE";
                    break;

                case 'OPTIONS' :
                    $curlMethod = CURLOPT_CUSTOMREQUEST;
                    $curlValue = "OPTIONS";
                    break;

                case 'TRACE' :
                    $curlMethod = CURLOPT_CUSTOMREQUEST;
                    $curlValue = "TRACE";
                    break;

                case 'HEAD' :
                    $curlMethod = CURLOPT_CUSTOMREQUEST;
                    $curlValue = "HEAD";
                    break;

                default:
                    // For now, through an exception for unsupported request methods
                    throw new AdapterException\InvalidArgumentException("Method currently not supported");
            }
            if (isset($request['body'])) {
                if (is_resource($request['body']) && $curlMethod != CURLOPT_PUT) {
                    throw new AdapterException\RuntimeException("Streaming requests are allowed only with PUT");
                }
            }

            // get http version to use
            $curlHttp = (isset($request['httpVersion']) && $request['httpVersion'] == 1.1) ? CURL_HTTP_VERSION_1_1
                    : CURL_HTTP_VERSION_1_0;

            // mark as HTTP request and set HTTP method
            curl_setopt($this->curl[$k], $curlHttp, true);
            curl_setopt($this->curl[$k], $curlMethod, $curlValue);
            if ($this->config['outputstream']) {
                // headers will be read into the response
                curl_setopt($this->curl[$k], CURLOPT_HEADER, false);
                curl_setopt($this->curl[$k], CURLOPT_HEADERFUNCTION, array($this, "readHeader"));
                // and data will be written into the file
                curl_setopt($this->curl[$k], CURLOPT_FILE, $this->outputStream);
            } else {
                // ensure headers are also returned
                curl_setopt($this->curl[$k], CURLOPT_HEADER, 0);

                // ensure actual response is returned
                curl_setopt($this->curl[$k], CURLOPT_RETURNTRANSFER, true);
                if (isset($request['http_referer'])) {
                    curl_setopt($this->curl[$k], CURLOPT_REFERER, $request['http_referer']);
                }
            }
            // set additional headers
            $request['headers']['Accept'] = '';
            curl_setopt($this->curl[$k], CURLOPT_HTTPHEADER, $request['headers']);
            /**
             * Make sure POSTFIELDS is set after $curlMethod is set:
             * @link http://de2.php.net/manual/en/function.curl-setopt.php#81161
             */
            if ($request['method'] == 'POST') {
                curl_setopt($this->curl[$k], CURLOPT_POSTFIELDS, $request['body']);
            } elseif ($curlMethod == CURLOPT_PUT) {
                // this covers a PUT by file-handle:
                // Make the setting of this options explicit (rather than setting it through the loop following a bit lower)
                // to group common functionality together.
                curl_setopt($this->curl[$k], CURLOPT_INFILE, $this->config['curloptions'][CURLOPT_INFILE]);
                curl_setopt($this->curl[$k], CURLOPT_INFILESIZE, $this->config['curloptions'][CURLOPT_INFILESIZE]);
                unset($this->config['curloptions'][CURLOPT_INFILE]);
                unset($this->config['curloptions'][CURLOPT_INFILESIZE]);
            } elseif ($request['method'] == 'PUT') {
                // This is a PUT by a setRawData string, not by file-handle
                curl_setopt($this->curl[$k], CURLOPT_POSTFIELDS, $request['body']);
            }

            // set additional curl options
            if (isset($this->config['curloptions'])) {
                foreach ((array) $this->config['curloptions'] as $k => $v) {
                    if (!in_array($k, $this->invalidOverwritableCurlOptions)) {
                        if (curl_setopt($this->curl[$k], $k, $v) == false) {
                            throw new AdapterException\RuntimeException(sprintf("Unknown or erroreous cURL option '%s' set", $k));
                        }
                    }
                }
            }
            curl_multi_add_handle($this->mcurl, $this->curl[$k]);
        }

        // send the request
        $running = null;
        do {
            curl_multi_exec($this->mcurl, $running);
            usleep(10000);
        } while ($running > 0);

        $requestTexts = array();
        $responses = array();
        foreach ($this->curl as $key => $curl) {
            $request = curl_getinfo($curl, CURLINFO_HEADER_OUT);
            $request .= isset($requests[$key]['body']) ? $requests[$key]['body'] : '';
            $requestTexts[$key] = $request;
            $response = curl_multi_getcontent($curl);
            $this->cclose($curl, $key);
            $responses[$key] = $response;
        }
        $this->mclose();

        return $responses;
    }


    /**
     * Close the connection to the server
     *
     */
    public function cclose($curl, $key)
    {
        if (is_resource($curl)) {
            curl_multi_remove_handle($this->mcurl, $curl);
            curl_close($curl);
            unset($this->connectedTo[$key]);
        }
    }

    public function mclose()
    {
        if (is_resource($this->mcurl)) {
            curl_multi_close($this->mcurl);
        }
        $this->mcurl = null;
        $this->curl = array();
        $this->connectedTo = array(null, null);
    }
}


