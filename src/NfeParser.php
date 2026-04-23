<?php
declare(strict_types=1);

class NfeParser
{
    /**
     * Recebe o XML SOAP retornado pela SEFAZ (NFeDistribuicaoDFe)
     * e retorna os itens da NF-e como array.
     */
    public static function extrairItens(string $soapXml, string $chave): array
    {
        libxml_use_internal_errors(true);

        $envelope = simplexml_load_string($soapXml);
        if (!$envelope) {
            libxml_clear_errors();
            $preview = substr(strip_tags($soapXml), 0, 300);
            throw new RuntimeException('Resposta SEFAZ inválida: ' . $preview);
        }

        // Localiza retDistDFeInt independente de namespace
        $retNodes = $envelope->xpath('//*[local-name()="retDistDFeInt"]');
        if (empty($retNodes)) {
            throw new RuntimeException('Elemento retDistDFeInt não encontrado na resposta SEFAZ.');
        }
        $ret = $retNodes[0];

        $cStat   = (string)($ret->xpath('*[local-name()="cStat"]')[0]   ?? '');
        $xMotivo = (string)($ret->xpath('*[local-name()="xMotivo"]')[0] ?? '');

        if ($cStat === '137') {
            throw new RuntimeException("NF-e não encontrada na SEFAZ (cStat 137). Verifique se o CNPJ da transportadora consta na nota.");
        }
        if ($cStat !== '138') {
            throw new RuntimeException("SEFAZ cStat={$cStat}: {$xMotivo}");
        }

        $docZips = $ret->xpath('//*[local-name()="docZip"]');
        if (empty($docZips)) {
            throw new RuntimeException('NF-e não disponível para download (sem docZip). Pode ter expirado o prazo de 90 dias.');
        }

        foreach ($docZips as $docZip) {
            $schema = (string)($docZip->attributes()['schema'] ?? '');
            // Aceita procNFe, nfeProc ou NFe
            if (!preg_match('/procNFe|nfeProc|_NFe_/i', $schema)) {
                continue;
            }

            $compressed = base64_decode((string)$docZip);
            $xmlNFe     = @gzdecode($compressed);

            if (!$xmlNFe) {
                // Tenta gzinflate (deflate raw) como fallback
                $xmlNFe = @gzinflate(substr($compressed, 10, -8));
            }

            if (!$xmlNFe) {
                throw new RuntimeException('Falha ao descomprimir XML da NF-e.');
            }

            return self::parsearNFe($xmlNFe);
        }

        throw new RuntimeException('Documento NF-e não localizado no lote retornado pela SEFAZ.');
    }

    private static function parsearNFe(string $xmlContent): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        libxml_clear_errors();

        if (!$xml) {
            throw new RuntimeException('Erro ao processar XML da NF-e após descompressão.');
        }

        $dets  = $xml->xpath('//*[local-name()="det"]');
        $itens = [];

        foreach ($dets as $det) {
            $prod = $det->xpath('*[local-name()="prod"]')[0] ?? null;
            if (!$prod) {
                continue;
            }

            $codigo     = trim((string)($prod->xpath('*[local-name()="cProd"]')[0]  ?? ''));
            $descricao  = trim((string)($prod->xpath('*[local-name()="xProd"]')[0]  ?? ''));
            $ncm        = trim((string)($prod->xpath('*[local-name()="NCM"]')[0]    ?? ''));
            $unidade    = trim((string)($prod->xpath('*[local-name()="uCom"]')[0]   ?? ''));
            $quantidade = (float)(string)($prod->xpath('*[local-name()="qCom"]')[0] ?? 0);
            $valorUnit  = (float)(string)($prod->xpath('*[local-name()="vUnCom"]')[0] ?? 0);

            if ($codigo === '') {
                continue;
            }

            $itens[] = [
                'codigo'     => $codigo,
                'descricao'  => $descricao,
                'ncm'        => $ncm,
                'unidade'    => $unidade,
                'quantidade' => $quantidade,
                'valor_unit' => $valorUnit,
            ];
        }

        return $itens;
    }
}
