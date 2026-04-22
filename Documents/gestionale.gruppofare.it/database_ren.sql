-- Tabella per le richieste REN
CREATE TABLE IF NOT EXISTS ren_richieste (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    email VARCHAR(255) NOT NULL,
    documento_identita VARCHAR(255) NOT NULL,
    codice_fiscale VARCHAR(16) NOT NULL,
    via VARCHAR(255) NOT NULL,
    cap VARCHAR(10) NOT NULL,
    comune VARCHAR(100) NOT NULL,
    provincia VARCHAR(2) NOT NULL,
    tetto_tipo ENUM('falde', 'piano') NOT NULL,
    stato ENUM('in_attesa', 'accettato', 'rifiutato', 'da_integrare') DEFAULT 'in_attesa',
    note TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES utenti(id)
);

-- Tabella per gli allegati
CREATE TABLE IF NOT EXISTS ren_allegati (
    id INT AUTO_INCREMENT PRIMARY KEY,
    richiesta_id INT NOT NULL,
    tipo ENUM(
        'isee',
        'certificato_residenza',
        'titolo_valido',
        'bolletta_energia',
        'documento_identita_cf',
        'richiesta_firmata'
    ) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (richiesta_id) REFERENCES ren_richieste(id) ON DELETE CASCADE
);
