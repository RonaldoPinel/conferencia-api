<?php
declare(strict_types=1);

/* ============================================================
   LISTAR conferências (opcionalmente por romaneio)
   GET /conferencias?id_romaneio=X
   ============================================================ */
function route_listar_conferencias(): void
{
    $db         = Database::get();
    $idRomaneio = isset($_GET['id_romaneio']) ? (int)$_GET['id_romaneio'] : 0;

    if ($idRomaneio > 0) {
        $stmt = $db->prepare('SELECT * FROM conferencias WHERE id_romaneio = ? ORDER BY created_at DESC');
        $stmt->execute([$idRomaneio]);
    } else {
        $stmt = $db->prepare('SELECT * FROM conferencias ORDER BY created_at DESC LIMIT 100');
        $stmt->execute();
    }

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

/* ============================================================
   CRIAR conferência + itens esperados
   POST /conferencias
   Body: { id_romaneio, data_saida, placa, motorista, transportadora, itens[] }
   ============================================================ */
function route_criar_conferencia(): void
{
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $idRomaneio     = (int)($data['id_romaneio'] ?? 0);
    $dataSaida      = $data['data_saida']     ?? null;
    $placa          = $data['placa']          ?? null;
    $motorista      = $data['motorista']      ?? null;
    $transportadora = $data['transportadora'] ?? null;
    $itens          = $data['itens']          ?? [];

    if ($idRomaneio <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'erro' => 'id_romaneio obrigatório.']);
        return;
    }

    $db = Database::get();
    $db->beginTransaction();

    try {
        // Reutiliza conferência em andamento se já existir
        $stmt = $db->prepare("SELECT id FROM conferencias WHERE id_romaneio = ? AND status = 'em_andamento' LIMIT 1");
        $stmt->execute([$idRomaneio]);
        $existing = $stmt->fetch();

        if ($existing) {
            $db->rollBack();
            echo json_encode(['success' => true, 'id' => (int)$existing['id'], 'reaproveitado' => true]);
            return;
        }

        $stmt = $db->prepare('INSERT INTO conferencias (id_romaneio, data_saida, placa, motorista, transportadora, total_itens) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$idRomaneio, $dataSaida, $placa, $motorista, $transportadora, count($itens)]);
        $id = (int)$db->lastInsertId();

        if (!empty($itens)) {
            $stmtItem = $db->prepare('INSERT INTO conferencia_itens (id_conferencia, codigo_produto, descricao, ncm, unidade, qtd_esperada) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($itens as $item) {
                $stmtItem->execute([
                    $id,
                    $item['codigo']    ?? '',
                    $item['descricao'] ?? null,
                    $item['ncm']       ?? null,
                    $item['unidade']   ?? null,
                    (float)($item['qtd_total'] ?? 0),
                ]);
            }
        }

        $db->commit();
        echo json_encode(['success' => true, 'id' => $id, 'reaproveitado' => false]);

    } catch (Throwable $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'erro' => $e->getMessage()]);
    }
}

/* ============================================================
   BUSCAR conferência com itens
   GET /conferencias/{id}
   ============================================================ */
function route_buscar_conferencia(int $id): void
{
    $db   = Database::get();
    $stmt = $db->prepare('SELECT * FROM conferencias WHERE id = ?');
    $stmt->execute([$id]);
    $conf = $stmt->fetch();

    if (!$conf) {
        http_response_code(404);
        echo json_encode(['success' => false, 'erro' => 'Conferência não encontrada.']);
        return;
    }

    $stmt = $db->prepare('SELECT * FROM conferencia_itens WHERE id_conferencia = ? ORDER BY codigo_produto');
    $stmt->execute([$id]);
    $conf['itens'] = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $conf]);
}

/* ============================================================
   SALVAR contagem dos itens
   PUT /conferencias/{id}
   Body: { itens: [{ codigo, qtd_conferida, observacao }] }
   ============================================================ */
function route_salvar_itens(int $id): void
{
    $data  = json_decode(file_get_contents('php://input'), true) ?? [];
    $itens = $data['itens'] ?? [];

    $db   = Database::get();
    $stmt = $db->prepare("SELECT status FROM conferencias WHERE id = ?");
    $stmt->execute([$id]);
    $conf = $stmt->fetch();

    if (!$conf) {
        http_response_code(404);
        echo json_encode(['success' => false, 'erro' => 'Conferência não encontrada.']);
        return;
    }
    if ($conf['status'] === 'finalizada') {
        http_response_code(400);
        echo json_encode(['success' => false, 'erro' => 'Conferência já finalizada.']);
        return;
    }

    $db->beginTransaction();
    try {
        $stmtEsp = $db->prepare('SELECT qtd_esperada FROM conferencia_itens WHERE id_conferencia = ? AND codigo_produto = ?');
        $stmtUpd = $db->prepare('UPDATE conferencia_itens SET qtd_conferida = ?, status = ?, observacao = ?, updated_at = NOW() WHERE id_conferencia = ? AND codigo_produto = ?');

        foreach ($itens as $item) {
            $codigo    = $item['codigo'] ?? '';
            $qtdConf   = isset($item['qtd_conferida']) && $item['qtd_conferida'] !== '' ? (float)$item['qtd_conferida'] : null;
            $observacao = $item['observacao'] ?? null;

            $status = 'pendente';
            if ($qtdConf !== null) {
                $stmtEsp->execute([$id, $codigo]);
                $row    = $stmtEsp->fetch();
                $qtdEsp = $row ? (float)$row['qtd_esperada'] : 0.0;
                $status = abs($qtdConf - $qtdEsp) < 0.0001 ? 'ok' : 'divergencia';
            }

            $stmtUpd->execute([$qtdConf, $status, $observacao, $id, $codigo]);
        }

        $db->commit();
        atualizarTotais($db, $id);
        echo json_encode(['success' => true]);

    } catch (Throwable $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'erro' => $e->getMessage()]);
    }
}

/* ============================================================
   FINALIZAR conferência
   PUT /conferencias/{id}/finalizar
   ============================================================ */
function route_finalizar_conferencia(int $id): void
{
    $db   = Database::get();
    $stmt = $db->prepare("UPDATE conferencias SET status = 'finalizada', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    atualizarTotais($db, $id);
    echo json_encode(['success' => true]);
}

/* ============================================================
   Recalcula os totais da conferência
   ============================================================ */
function atualizarTotais(PDO $db, int $id): void
{
    $stmt = $db->prepare("
        UPDATE conferencias SET
            total_itens        = (SELECT COUNT(*)    FROM conferencia_itens WHERE id_conferencia = :id),
            total_conferidos   = (SELECT COUNT(*)    FROM conferencia_itens WHERE id_conferencia = :id AND status != 'pendente'),
            total_divergencias = (SELECT COUNT(*)    FROM conferencia_itens WHERE id_conferencia = :id AND status = 'divergencia'),
            updated_at         = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);
}
