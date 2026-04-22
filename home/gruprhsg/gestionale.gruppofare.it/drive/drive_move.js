document.addEventListener("DOMContentLoaded", () => {
    const rows = document.querySelectorAll("tr[data-file-id]");
    const cards = document.querySelectorAll(".drive-card[data-file-id]");
    const folders = document.querySelectorAll("tr[data-file-id][data-type='folder'], .drive-card[data-type='folder']");
    const gridContainer = document.querySelector(".drive-grid") || document.querySelector("table.drive-table");

    // Rendi draggable tutti gli elementi (righe e card)
    [...rows, ...cards].forEach(item => {
        if (item.getAttribute("draggable") !== "true") {
            item.setAttribute("draggable", "true");
        }

        item.addEventListener("dragstart", e => {
            const fileId = item.dataset.fileId;
            e.dataTransfer.setData("text/plain", fileId);
            e.dataTransfer.effectAllowed = "move";
            item.classList.add("dragging");
        });

        item.addEventListener("dragend", () => {
            item.classList.remove("dragging");
        });
    });

    // Rendi le cartelle zone di drop
    folders.forEach(folder => {
        folder.addEventListener("dragover", e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = "move";
            folder.classList.add("drag-over");
        });

        folder.addEventListener("dragleave", e => {
            if (!folder.contains(e.relatedTarget)) {
                folder.classList.remove("drag-over");
            }
        });

        folder.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            folder.classList.remove('drag-over');
            
            const fileId = e.dataTransfer.getData('text/plain');
            const targetFolderId = folder.dataset.fileId;
            
            if (!fileId || !targetFolderId) {
                console.error('ID file o cartella mancante');
                return;
            }
            
            if (fileId === targetFolderId) {
                alert('Non puoi spostare una cartella dentro sé stessa');
                return;
            }
            
            folder.classList.add('drop-success');
            
            const formData = new FormData();
            formData.append('movefileid', fileId);
            formData.append('targetfolder', targetFolderId);
            
            // Nascondi immediatamente l'elemento spostato
            const movedElement = document.querySelector(`[data-file-id="${fileId}"]`);
            if (movedElement) {
                movedElement.style.opacity = '0.3';
                movedElement.style.pointerEvents = 'none';
            }
            
            fetch('move_file.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Rimuovi immediatamente dal DOM
                    if (movedElement) {
                        movedElement.remove();
                    }
                    
                    // Mostra notifica
                    showNotification('✅ File spostato con successo!', 'success');
                    
                    // Ricarica dopo 500ms
                    setTimeout(() => location.reload(), 500);
                } else {
                    // Ripristina visibilità in caso di errore
                    if (movedElement) {
                        movedElement.style.opacity = '1';
                        movedElement.style.pointerEvents = 'auto';
                    }
                    alert(data.error || 'Errore nello spostamento');
                }
                folder.classList.remove('drop-success');
            })
            .catch(err => {
                console.error('Errore nello spostamento:', err);
                if (movedElement) {
                    movedElement.style.opacity = '1';
                    movedElement.style.pointerEvents = 'auto';
                }
                alert('Errore nello spostamento del file');
                folder.classList.remove('drop-success');
            });
        });
    });

    // Permetti lo spostamento nella HOME (drop fuori da qualsiasi cartella)
    if (gridContainer) {
        gridContainer.addEventListener("dragover", e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = "move";
            gridContainer.classList.add("drag-over-root");
        });

        gridContainer.addEventListener("dragleave", e => {
            if (!gridContainer.contains(e.relatedTarget)) {
                gridContainer.classList.remove("drag-over-root");
            }
        });

        gridContainer.addEventListener("drop", e => {
            e.preventDefault();
            e.stopPropagation();
            gridContainer.classList.remove("drag-over-root");

            const fileId = e.dataTransfer.getData("text/plain");
            if (!fileId) return;

            // Nascondi immediatamente l'elemento spostato
            const movedElement = document.querySelector(`[data-file-id="${fileId}"]`);
            if (movedElement) {
                movedElement.style.opacity = '0.3';
                movedElement.style.pointerEvents = 'none';
            }

            const formData = new FormData();
            formData.append("movefileid", fileId);
            formData.append("targetfolder", ""); // vuoto = home

            fetch("move_file.php", {
                method: "POST",
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Rimuovi immediatamente dal DOM
                    if (movedElement) {
                        movedElement.remove();
                    }
                    
                    // Mostra notifica
                    showNotification('✅ File spostato nella home!', 'success');
                    
                    // Ricarica dopo 500ms
                    setTimeout(() => location.reload(), 500);
                } else {
                    // Ripristina visibilità
                    if (movedElement) {
                        movedElement.style.opacity = '1';
                        movedElement.style.pointerEvents = 'auto';
                    }
                    alert("❌ " + (data.error || "Errore nello spostamento nella home"));
                }
            })
            .catch(err => {
                console.error("Errore nello spostamento:", err);
                if (movedElement) {
                    movedElement.style.opacity = '1';
                    movedElement.style.pointerEvents = 'auto';
                }
                alert("❌ Errore nello spostamento nella home");
            });
        });
    }
}); // ✅ Chiusura corretta del DOMContentLoaded

// Funzione di notifica (fuori dal DOMContentLoaded)
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#28a745' : '#dc3545'};
        color: white;
        padding: 15px 25px;
        border-radius: 12px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        z-index: 9999;
        font-weight: 600;
        animation: slideIn 0.3s ease;
    `;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 2000);
}
