<?php
class Property {
    private $conn;
    private $table_name = "properties";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function readAll() {
        $query = "SELECT id, reference_id, title, price, operation_type, status FROM " . $this->table_name . " ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function create($reference_id, $title, $price, $operation_type, $property_type) {
        $query = "INSERT INTO " . $this->table_name . " 
                  (reference_id, title, price, operation_type, property_type) 
                  VALUES (:reference_id, :title, :price, :operation_type, :property_type)";
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(":reference_id", $reference_id);
        $stmt->bindParam(":title", $title);
        $stmt->bindParam(":price", $price);
        $stmt->bindParam(":operation_type", $operation_type);
        $stmt->bindParam(":property_type", $property_type);
        
        if($stmt->execute()) { return true; }
        return false;
    }
}
?>
