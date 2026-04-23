<?php
declare(strict_types=1);

class Sefaz
{
    private string $certPem;
    private string $keyPem;
    private string $cnpj;
    private int    $cUF;

    private const URL_AN = 'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx';

    /**
     * @param string $tipo  'transp' (padrão) ou 'nac'
     */
    public function __construct(string $tipo = 'transp')
    {
        if ($tipo === 'nac') {
            $pfxPath  = __DIR__ . '/../cert/certificado_nac.pfx';
            $pfxPass  = $_ENV['CERT_PASSWORD_NAC'] ?? '';
            $this->cnpj = preg_replace('/\D/', '', $_ENV['CNPJ_NAC'] ?? '');
        } else {
            $pfxPath  = __DIR__ . '/../cert/certificado.pfx';
            $pfxPass  = $_ENV['CERT_PASSWORD'] ?? '';
            $this->cnpj = preg_replace('/\D/', '', $_ENV['CNPJ'] ?? '');
        }

        $this->cUF = (int)($_ENV['UF_IBGE'] ?? 35);

        if (!file_exists($pfxPath)) {
            throw new RuntimeException("Certificado .pfx não encontrado: {$pfxPath}");
        }

        $pfxData = file_get_contents($pfxPath);
        $certs   = [];

        if (!openssl_pkcs12_read($pfxData, $certs, $pfxPass)) {
            throw new RuntimeException("Erro ao ler certificado .pfx ({$tipo}). Verifique a senha.");
        }

        $this->certPem = $certs['cert'];
        $this->keyPem  = $certs['pkey'];
    }

    public function buscarItensPorChave(string $chave): array
    {
        $soapBody = $this->montarEnvelope($chave);
        $resposta = $this->enviarSoap($soapBody);
        return NfeParser::extrairItens($resposta, $chave);
    }

    private function montarEnvelope(string $chave): string
    {
        $cnpj  = htmlspecialchars($this->cnpj, ENT_XML1);
        $chave = htmlspecialchars($chave, ENT_XML1);
        $cUF   = $this->cUF;

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:wsdl="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">
  <soapenv:Header/>
  <soapenv:Body>
    <wsdl:nfeDistDFeInteresse>
      <wsdl:nfeDadosMsg>
        <distDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">
          <tpAmb>1</tpAmb>
          <cUFAutor>{$cUF}</cUFAutor>
          <CNPJ>{$cnpj}</CNPJ>
          <consChNFe>
            <chNFe>{$chave}</chNFe>
          </consChNFe>
        </distDFeInt>
      </wsdl:nfeDadosMsg>
    </wsdl:nfeDistDFeInteresse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function enviarSoap(string $body): string
    {
        $certFile = tempnam(sys_get_temp_dir(), 'nfe_cert_');
        $keyFile  = tempnam(sys_get_temp_dir(), 'nfe_key_');

        file_put_contents($certFile, $this->certPem);
        file_put_contents($keyFile,  $this->keyPem);

        try {
            $ch = curl_init(self::URL_AN);

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: "http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse"',
                ],
                CURLOPT_SSLCERT        => $certFile,
                CURLOPT_SSLKEY         => $keyFile,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $resposta = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErro = curl_error($ch);
            curl_close($ch);

            if ($curlErro) {
                throw new RuntimeException("Erro cURL: {$curlErro}");
            }
            if ($httpCode >= 400) {
                throw new RuntimeException("SEFAZ HTTP {$httpCode}.");
            }

            return $resposta;

        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    }
}
