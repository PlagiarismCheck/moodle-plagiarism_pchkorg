<?php


class ApiProvider
{

    private $token;
    /**
     * @var string
     */
    private $endpoint;

    private $lastError;

    /**
     * @return mixed
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * @param mixed $lastError
     */
    public function setLastError($lastError)
    {
        $this->lastError = $lastError;
    }

    public function __construct($token, $endpoint = 'https://plagiarismcheck.org')
    {
        $this->token    = $token;
        $this->endpoint = $endpoint;
    }

    private function generateParameters($data)
    {
        $peirs = [];

        foreach ($data as $name => $value) {
            $peirs[] = urlencode($name) . '=' . urlencode($value);
        }

        return implode('&', $peirs);
    }

    public function sendText($content, $mime, $filename)
    {
        $boundary = sprintf('PLAGCHECKBOUNDARY-%s', uniqid(time()));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint . '/api/v1/text',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'X-API-TOKEN: ' . $this->token,
                'Content-Type: multipart/form-data; boundary=' . $boundary
            ],
            CURLOPT_POSTFIELDS     => $this->getBody($boundary, $content, $mime, $filename),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $id = null;
        if ($json = json_decode($response)) {
            if (isset($json->message)) {
                $this->setLastError($json->message);
                return null;
            }
            if (isset($json->success) && $json->success) {
                $id = $json->data->text->id;
            }
        }

        return $id;
    }

    private function getPart($name, $value, $boundary)
    {
        $eol = "\r\n";

        $part = '--' . $boundary . $eol;
        $part .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
        $part .= $value . $eol;

        return $part;
    }

    private function getFilePart($name, $value, $mime, $filename, $boundary)
    {
        $eol = "\r\n";

        $part = '--' . $boundary . $eol;
        $part .= 'Content-Disposition: form-data; name="' . $name . '"; filename="'.$filename.'";' . $eol;
        $part .= 'Content-Type: ' . $mime . $eol;
        $part .= 'Content-Length: ' . strlen($value) . $eol . $eol;
        $part .= $value . $eol;

        return $part;
    }

    private function getBody($boundary, $content, $mime, $filename)
    {
        $eol = "\r\n";

        $body  = '';
        $body .= $this->getPart('language', 'en', $boundary);
        $body .= $this->getFilePart('text', $content, $mime, $filename, $boundary);
        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    public function checkText($textId)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint . '/api/v1/text/' . $textId,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST           => false,
            CURLOPT_HTTPHEADER     => [
                'X-API-TOKEN: ' . $this->token,
                'Content-Type: application/x-www-form-urlencoded'
            ],
        ]);

        $response = curl_exec($ch);
        $id       = null;
        if ($json = json_decode($response)) {
            if (5 == $json->data->state) {
                $id = $json->data->report->id;
            }
        }

        curl_close($ch);

        return $id;
    }

    public function getReport($id)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint . '/api/v1/text/report/' . $id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST           => false,
            CURLOPT_HTTPHEADER     => [
                'X-API-TOKEN: ' . $this->token,
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    public function isSupportedMime($mime)
    {
        return in_array($mime, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/rtf',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
            'application/pdf',
        ], true);
    }
}
