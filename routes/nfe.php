<?php
declare(strict_types=1);

function route_nfe_itens(): void
{
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $chaves = $input['chaves'] ?? [];

    if (empty($chaves) || !is_array($chaves)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'erro' => 'Parâmetro "chaves" (array) é obrigatório.']);
        return;
    }

    $sefaz    = new Sefaz();
    $agrupado = [];
    $erros    = [];

    foreach ($chaves as $chave) {
        $chave = preg_replace('/\D/', '', (string)$chave);

        if (strlen($chave) !== 44) {
            $erros[] = ['chave' => $chave, 'erro' => 'Chave inválida (deve ter 44 dígitos).'];
            continue;
        }

        try {
            $itens = $sefaz->buscarItensPorChave($chave);

            foreach ($itens as $item) {
                $cod = $item['codigo'];

                if (!isset($agrupado[$cod])) {
                    $agrupado[$cod] = [
                        'codigo'    => $cod,
                        'descricao' => $item['descricao'],
                        'ncm'       => $item['ncm'],
                        'unidade'   => $item['unidade'],
                        'qtd_total' => 0.0,
                        'nfes'      => [],
                    ];
                }

                $agrupado[$cod]['qtd_total'] += $item['quantidade'];
                $agrupado[$cod]['nfes'][]     = $chave;
            }
        } catch (Throwable $e) {
            $erros[] = ['chave' => $chave, 'erro' => $e->getMessage()];
        }
    }

    // Remove duplicatas nas chaves por item
    foreach ($agrupado as &$item) {
        $item['nfes']      = array_values(array_unique($item['nfes']));
        $item['qtd_total'] = round($item['qtd_total'], 4);
    }
    unset($item);

    // Ordena por código
    usort($agrupado, fn($a, $b) => strcmp($a['codigo'], $b['codigo']));

    echo json_encode([
        'success' => true,
        'itens'   => array_values($agrupado),
        'erros'   => $erros,
    ]);
}
