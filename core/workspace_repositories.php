<?php
declare(strict_types=1);

/** Narrow data-access adapters. Callers must supply the authenticated scope explicitly. */
final class FamsAicsRecordRepository {
    private const TABLE = 'aics_assistance_records';
    public function __construct(private PDO $db) {}
    public function findInOffice(int $recordId, int $officeId, bool $forUpdate = false): ?array {
        if ($recordId < 1 || $officeId < 1) return null;
        $sql = 'SELECT * FROM '.self::TABLE.' WHERE id=? AND office_id=? LIMIT 1'.($forUpdate ? ' FOR UPDATE' : '');
        $st=$this->db->prepare($sql); $st->execute([$recordId,$officeId]); $row=$st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function findAnyForDistrictRead(int $recordId, bool $forUpdate = false): ?array {
        if ($recordId < 1) return null;
        $sql = 'SELECT * FROM '.self::TABLE.' WHERE id=? LIMIT 1'.($forUpdate ? ' FOR UPDATE' : '');
        $st=$this->db->prepare($sql); $st->execute([$recordId]); $row=$st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function updateInOfficeAtVersion(int $recordId, int $officeId, int $expectedVersion, array $columns): bool {
        return $this->compareAndSwap($recordId,$expectedVersion,$columns,'office_id=?',[$officeId]);
    }
    public function updateAnyAtVersion(int $recordId, int $expectedVersion, array $columns): bool {
        return $this->compareAndSwap($recordId,$expectedVersion,$columns,'1=1',[]);
    }
    private function compareAndSwap(int $id,int $version,array $columns,string $scopeSql,array $scopeParams): bool {
        if ($id<1 || $version<1 || !$columns) throw new InvalidArgumentException('A record ID, version, and update fields are required.');
        $allowed=['status','validity_status','validity_days','validity_expires_at','remarks','release_date','month_of_release','updated_by','cancelled_flag','record_version'];
        $sets=[];$values=[];
        foreach($columns as $column=>$value){if(!in_array($column,$allowed,true))throw new InvalidArgumentException('Unsupported AICS record field: '.$column);if($column==='record_version')continue;$sets[]='`'.$column.'`=?';$values[]=$value;}
        if(!$sets) throw new InvalidArgumentException('No permitted AICS fields were supplied.');
        $sets[]='record_version=record_version+1';
        $sql='UPDATE '.self::TABLE.' SET '.implode(',',$sets).' WHERE id=? AND record_version=? AND '.$scopeSql;
        $st=$this->db->prepare($sql);$st->execute([...$values,$id,$version,...$scopeParams]);
        return $st->rowCount()===1;
    }
}

final class FamsMaifipRecordRepository {
    private const TABLE = 'assistance_records';
    public function __construct(private PDO $db) {}
    public function findInFacility(int $recordId, string $facility, bool $forUpdate = false): ?array {
        $facility=strtoupper(trim($facility)); if($recordId<1 || $facility==='') return null;
        $sql='SELECT * FROM '.self::TABLE.' WHERE id=? AND UPPER(TRIM(facility))=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
        $st=$this->db->prepare($sql);$st->execute([$recordId,$facility]);$row=$st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function updateInFacilityAtVersion(int $recordId,string $facility,int $expectedVersion,array $columns): bool {
        $facility=strtoupper(trim($facility));
        if($recordId<1||$facility===''||$expectedVersion<1||!$columns)throw new InvalidArgumentException('A record, facility, version, and update fields are required.');
        $allowed=['status','remarks','release_date','updated_by','cancelled_flag','record_version'];$sets=[];$values=[];
        foreach($columns as $column=>$value){if(!in_array($column,$allowed,true))throw new InvalidArgumentException('Unsupported MAIFIP record field: '.$column);if($column==='record_version')continue;$sets[]='`'.$column.'`=?';$values[]=$value;}
        if(!$sets)throw new InvalidArgumentException('No permitted MAIFIP fields were supplied.');
        $sets[]='record_version=record_version+1';
        $st=$this->db->prepare('UPDATE '.self::TABLE.' SET '.implode(',',$sets).' WHERE id=? AND record_version=? AND UPPER(TRIM(facility))=?');
        $st->execute([...$values,$recordId,$expectedVersion,$facility]);return $st->rowCount()===1;
    }
}
