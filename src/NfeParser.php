<?php
declare(strict_types=1);

class NfeParser
{
    public static function extrairItens(string $soapXml, string $chave): array
    {
        libxml_use_internal_errors(true);

        // Estratégia 1: parse direto do envelope SOAP
        $ret = self::encontrarRetDistDFeInt($soapXml);

        // Estratégia 2: extrai o bloco <retDistDFeInt>...</retDistDFeInt> via regex e tenta de novo
        if ($ret === null) {
            if (preg_match('/<retDistDFeInt[\s\S]*?<\/retDistDFeInt>/i', $soapXml, $m)) {
                $ret = self::encontrarRetDistDFeInt($m[0]);
            }
        }

        if ($ret === null) {
            libxml_clear_errors();
            $preview = substr(strip_tags($soapXml), 0, 400);
            throw new RuntimeException('Não foi possível interpretar resposta SEFAZ: ' . $preview);
        }

        libxml_clear_errors();

        $cStat   = (string)($ret->xpath('*[local-name()="cStat"]')[0]   ?? '');
        $xMotivo = (string)($ret->xpath('*[local-name()="xMotivo"]')[0] ?? '');

        if ($cStat === '137') {
            throw new RuntimeException('NF-e não encontrada na SEFAZ. O CNPJ da transportadora não consta na nota.');
        }
        if ($cStat !== '138') {
            throw new RuntimeException("SEFAZ cStat={$cStat}: {$xMotivo}");
        }

        $docZips = $ret->xpath('//*[local-name()="docZip"]');
        if (empty($docZips)) {
            throw new RuntimeException('NF-e sem docZip na resposta. Pode ter expirado o prazo de 90 dias.');
        }

        foreach ($docZips as $docZip) {
            $schema = (string)($docZip->attributes()['schema'] ?? '');
            if (!preg_match('/procNFe|nfeProc|NFe_/i', $schema)) {
                continue;
            }

            $compressed = base64_decode((string)$docZip);
            $xmlNFe     = @gzdecode($compressed);
            if (!$xmlNFe) {
                $xmlNFe = @gzinflate(substr($compressed, 10, -8));
            }
            if (!$xmlNFe) {
                throw new RuntimeException('Falha ao descomprimir XML da NF-e.');
            }

            return self::parsearNFe($xmlNFe);
        }

        throw new RuntimeException('Documento NF-e não localizado no lote SEFAZ.');
    }

    /**
     * Tenta parsear o XML e retornar o nó retDistDFeInt.
     * Usa DOMDocument como fallback se simplexml falhar.
     */
    private static function encontrarRetDistDFeInt(string $xml): ?\SimpleXMLElement
    {
        // Tenta com simplexml
        $obj = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($obj) {
            $nodes = $obj->xpath('//*[local-name()="retDistDFeInt"]');
            if (!empty($nodes)) {
                return $nodes[0];
            }
            // Pode ser o próprio elemento raiz
            if (strpos($obj->getName(), 'retDistDFeInt') !== false) {
                return $obj;
            }
        }

        // Fallback: DOMDocument com modo recovery
        $dom = new DOMDocument();
        if (@$dom->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOERROR)) {
            $items = $dom->getElementsByTagNameNS('*', 'retDistDFeInt');
            if ($items->length === 0) {
                $items = $dom->getElementsByTagName('retDistDFeInt');
            }
            if ($items->length > 0) {
                return simplexml_import_dom($items->item(0));
            }
        }

        return null;
    }

    private static function parsearNFe(string $xmlContent): array
    {
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);
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

            $codigo     = trim((string)($prod->xpath('*[local-name()="cProd"]')[0]   ?? ''));
            $descricao  = trim((string)($prod->xpath('*[local-name()="xProd"]')[0]   ?? ''));
            $ncm        = trim((string)($prod->xpath('*[local-name()="NCM"]')[0]     ?? ''));
            $unidade    = trim((string)($prod->xpath('*[local-name()="uCom"]')[0]    ?? ''));
            $quantidade = (float)(string)($prod->xpath('*[local-name()="qCom"]')[0]  ?? 0);
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
