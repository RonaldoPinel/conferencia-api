<?php
declare(strict_types=1);

class Sefaz
{
    private string $certPem;
    private string $keyPem;
    private string $cnpj;
    private int    $cUF;

    // Mapeamento de cUF (código IBGE) para URL do webservice NFeDistribuicaoDFe
    private const URLS = [
        // Ambiente Nacional (maioria dos estados)
        'default' => 'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx',
    ];

    public function __construct()
    {
        $pfxPath = __DIR__ . '/../cert/certificado.pfx';
        $pfxPass = $_ENV['CERT_PASSWORD'] ?? '';
        $this->cnpj = preg_replace('/\D/', '', $_ENV['CNPJ'] ?? '');
        $this->cUF  = (int)($_ENV['UF_IBGE'] ?? 35);

        if (!file_exists($pfxPath)) {
            throw new RuntimeException('Certificado .pfx não encontrado em cert/certificado.pfx');
        }

        $pfxData = file_get_contents($pfxPath);
        $certs   = [];

        if (!openssl_pkcs12_read($pfxData, $certs, $pfxPass)) {
            throw new RuntimeException('Erro ao ler certificado .pfx — verifique a senha em CERT_PASSWORD.');
        }

        $this->certPem = $certs['cert'];
        $this->keyPem  = $certs['pkey'];
    }

    /**
     * Busca os itens de uma NF-e na SEFAZ via NFeDistribuicaoDFe.
     * Retorna array de itens: [codigo, descricao, ncm, unidade, quantidade, valor_unit]
     */
    public function buscarItensPorChave(string $chave): array
    {
        $soapBody = $this->montarEnvelope($chave);
        $resposta = $this->enviarSoap($soapBody);
        return NfeParser::extrairItens($resposta, $chave);
    }

    private function montarEnvelope(string $chave): string
    {
        $cnpj = htmlspecialchars($this->cnpj, ENT_XML1);
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
        // Escreve cert e chave em arquivos temporários pois cURL precisa de caminhos
        $certFile = tempnam(sys_get_temp_dir(), 'nfe_cert_');
        $keyFile  = tempnam(sys_get_temp_dir(), 'nfe_key_');

        file_put_contents($certFile, $this->certPem);
        file_put_contents($keyFile,  $this->keyPem);

        try {
            $ch = curl_init(self::URLS['default']);

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
                throw new RuntimeException("Erro cURL ao consultar SEFAZ: {$curlErro}");
            }
            if ($httpCode >= 400) {
                throw new RuntimeException("SEFAZ retornou HTTP {$httpCode}.");
            }

            return $resposta;

        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    }
}
