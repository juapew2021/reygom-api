<?php
class Lead {
    private $conn;
    private $table_name = "leads";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function readAll() {
        $query = "SELECT
                    l.id,
                    l.full_name,
                    l.pipeline_stage,
                    l.contact_info,
                    l.property_interest_id,
                    p.title as property_title,
                    p.reference_id as property_reference,
                    l.created_at
                  FROM " . $this->table_name . " l
                  LEFT JOIN properties p ON l.property_interest_id = p.id
                  ORDER BY l.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function readOne($id) {
        $query = "SELECT
                    l.id,
                    l.full_name,
                    l.pipeline_stage,
                    l.contact_info,
                    l.property_interest_id,
                    p.title as property_title,
                    p.reference_id as property_reference,
                    l.created_at
                  FROM " . $this->table_name . " l
                  LEFT JOIN properties p ON l.property_interest_id = p.id
                  WHERE l.id = :id
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($full_name, $contact_info, $property_interest_id = null, $pipeline_stage = 'Nuevo') {
        $query = "INSERT INTO " . $this->table_name . "
                    (full_name, contact_info, property_interest_id, pipeline_stage)
                  VALUES
                    (:full_name, :contact_info, :property_interest_id, :pipeline_stage)
                  RETURNING id";

        $stmt = $this->conn->prepare($query);

        $contact_json = json_encode($contact_info);

        $stmt->bindParam(":full_name", $full_name);
        $stmt->bindParam(":contact_info", $contact_json);
        $stmt->bindParam(":property_interest_id", $property_interest_id);
        $stmt->bindParam(":pipeline_stage", $pipeline_stage);

        if ($stmt->execute()) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return false;
    }

    public function update($id, $full_name, $contact_info, $property_interest_id = null, $pipeline_stage = 'Nuevo') {
        $query = "UPDATE " . $this->table_name . "
                  SET
                    full_name = :full_name,
                    contact_info = :contact_info,
                    property_interest_id = :property_interest_id,
                    pipeline_stage = :pipeline_stage
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        $contact_json = json_encode($contact_info);

        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":full_name", $full_name);
        $stmt->bindParam(":contact_info", $contact_json);
        $stmt->bindParam(":property_interest_id", $property_interest_id);
        $stmt->bindParam(":pipeline_stage", $pipeline_stage);

        return $stmt->execute();
    }

    public function delete($id) {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);

        return $stmt->execute();
    }
}
?>
