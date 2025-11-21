<?php
class Audit {
  /** Loga um evento de auditoria.
   * @param PDO   $pdo
   * @param array $a  ['action','entity','entity_id','meta'(arr|string)]
   */
  public static function log(PDO $pdo, array $a): void {
    try {
      $userId = (int)($_SESSION['usuario_id'] ?? 0);
      $ip     = $_SERVER['REMOTE_ADDR']         ?? null;
      $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

      $meta = $a['meta'] ?? null;
      if (is_array($meta)) {
        $meta = json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      }

      $stmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, entity, entity_id, ip, user_agent, meta)
        VALUES (:user_id, :action, :entity, :entity_id, :ip, :ua, :meta)
      ");
      $stmt->execute([
        ':user_id'   => $userId,
        ':action'    => (string)$a['action'],
        ':entity'    => (string)$a['entity'],
        ':entity_id' => isset($a['entity_id']) ? (int)$a['entity_id'] : null,
        ':ip'        => $ip,
        ':ua'        => $ua,
        ':meta'      => $meta
      ]);
    } catch (Throwable $e) {
      // não quebra o fluxo da app se falhar log
      error_log('[AUDIT_FAIL] '.$e->getMessage());
    }
  }
}
