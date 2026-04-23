<?php
declare(strict_types=1);

function route_nfe_itens(): void
{
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $nfes   = $input['nfes'] ?? [];

    // Suporte ao formato antigo { chaves: [] }
    if (empty($nfes) && !empty($input['chaves'])) {
        $nfes = array_map(fn($c) => ['nfe' => $c, 'emitente' => 'transp'], $input['chaves']);
    }

    if (empty($nfes) || !is_array($nfes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'erro' => 'Parâmetro "nfes" (array) é obrigatório.']);
        return;
    }

    // Instancia os dois certificados uma vez só
    $sefazTransp = null;
    $sefazNac    = null;

    $agrupado = [];
    $erros    = [];

    foreach ($nfes as $item) {
        $chave    = preg_replace('/\D/', '', (string)($item['nfe']      ?? $item));
        $emitente = strtolower((string)($item['emitente'] ?? 'transp'));

        if (strlen($chave) !== 44) {
            $erros[] = ['chave' => $chave, 'erro' => 'Chave inválida (deve ter 44 dígitos).'];
            continue;
        }

        // Escolhe certificado: NAC para notas emitidas pela NAC, transportadora para o resto
        $usarNac = (strpos($emitente, 'nac') !== false);

        try {
            if ($usarNac) {
                if ($sefazNac === null) {
                    $sefazNac = new Sefaz('nac');
                }
                $itens = $sefazNac->buscarItensPorChave($chave);
            } else {
                if ($sefazTransp === null) {
                    $sefazTransp = new Sefaz('transp');
                }
                $itens = $sefazTransp->buscarItensPorChave($chave);
            }

            foreach ($itens as $it) {
                $cod = $it['codigo'];
                if (!isset($agrupado[$cod])) {
                    $agrupado[$cod] = [
                        'codigo'    => $cod,
                        'descricao' => $it['descricao'],
                        'ncm'       => $it['ncm'],
                        'unidade'   => $it['unidade'],
                        'qtd_total' => 0.0,
                        'nfes'      => [],
                    ];
                }
                $agrupado[$cod]['qtd_total'] += $it['quantidade'];
                $agrupado[$cod]['nfes'][]     = $chave;
            }
        } catch (\Throwable $e) {
            $erros[] = ['chave' => $chave, 'erro' => $e->getMessage()];
        }
    }

    foreach ($agrupado as &$it) {
        $it['nfes']      = array_values(array_unique($it['nfes']));
        $it['qtd_total'] = round($it['qtd_total'], 4);
    }
    unset($it);

    usort($agrupado, fn($a, $b) => strcmp($a['codigo'], $b['codigo']));

    echo json_encode([
        'success' => true,
        'itens'   => array_values($agrupado),
        'erros'   => $erros,
    ]);
}
