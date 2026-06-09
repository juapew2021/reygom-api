<?php
class Lead {
    private $conn;
    private $table_name = "leads";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function readAll() {
        $query = "SELECT l.id, l.full_name, l.pipeline_stage, l.contact_info, p.title as property_title 
                  FROM " . $this->table_name . " l
                  LEFT JOIN properties p ON l.property_interest_id = p.id 
                  ORDER BY l.created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function create($full_name, $contact_info, $property_interest_id = null) {
        $query = "INSERT INTO " . $this->table_name . " 
                  (full_name, contact_info, property_interest_id) 
                  VALUES (:full_name, :contact_info, :property_interest_id)";
        $stmt = $this->conn->prepare($query);
        
        $contact_json = json_encode($contact_info);
        
        $stmt->bindParam(":full_name", $full_name);
        $stmt->bindParam(":contact_info", $contact_json);
        $stmt->bindParam(":property_interest_id", $property_interest_id);
        
        if($stmt->execute()) { return true; }
        return false;
    }
}
?>
