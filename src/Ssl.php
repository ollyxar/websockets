<?php namespace Ollyxar\WebSockets;

class Ssl {
    public static function generateCert(string $certPath, string $pemPassPhrase): void
    {
        $certificateData = [
            "countryName"            => "UA",
            "stateOrProvinceName"    => "Kyiv",
            "localityName"           => "Kyiv",
            "organizationName"       => "customwebsite.com",
            "organizationalUnitName" => "customname",
            "commonName"             => "commoncustomname",
            "emailAddress"           => "custom@email.com"
        ];

        $privateKey = openssl_pkey_new();
        $certificate = openssl_csr_new($certificateData, $privateKey);
        $certificate = openssl_csr_sign($certificate, null, $privateKey, 365);

        $pem = [];
        openssl_x509_export($certificate, $pem[0]);
        openssl_pkey_export($privateKey, $pem[1], $pemPassPhrase);
        $pem = implode($pem);

        file_put_contents($certPath, $pem);
    }
}