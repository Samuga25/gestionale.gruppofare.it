<!-- TAB DOCUMENTI -->
<div class="tab-pane fade show active" id="tab-documenti">
    <div class="row">
        <div class="col-md-6">
            <h6><i class="fas fa-upload"></i> Carica PDF</h6>
            
            <!-- FORM UPLOAD SEMPRE VISIBILE -->
            <form id="form-upload-documento" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_documento">
                <input type="hidden" name="contratto_id" value="<?= $contratto_id ?>">
                <input type="hidden" name="is_temp" value="<?= $contratto_id > 0 ? '0' : '1' ?>">
                
                <div class="mb-3">
                    <label class="form-label">File PDF <span class="text-danger">*</span></label>
                    <input type="file" name="documento" id="file-documento" class="form-control" 
                           accept=".pdf" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Descrizione</label>
                    <input type="text" name="descrizione" class="form-control" 
                           placeholder="es. Contratto firmato">
                </div>
                
                <button type="submit" class="btn btn-success w-100">
                    <i class="fas fa-upload"></i> Carica PDF
                </button>
            </form>
            
            <?php if ($contratto_id === 0): ?>
            <div class="alert alert-info mt-3 mb-0">
                <i class="fas fa-info-circle"></i> I documenti saranno salvati quando salvi il contratto.
            </div>
            <?php endif; ?>
        </div>
        
        <div class="col-md-6">
            <h6><i class="fas fa-list"></i> PDF Caricati</h6>
            <div id="lista-documenti">
                <?php if ($contratto_id > 0): ?>
                    <!-- DOCUMENTI SALVATI NEL DB -->
                    <?php if (empty($documenti)): ?>
                        <p class="text-muted">Nessun documento caricato.</p>
                    <?php else: ?>
                        <?php foreach ($documenti as $doc): ?>
                        <div class="alert alert-light d-flex justify-content-between align-items-center mb-2" data-doc-id="<?= $doc['id'] ?>">
                            <div>
                                <strong><i class="fas fa-file-pdf text-danger"></i> <?= htmlspecialchars($doc['nome_file']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($doc['descrizione'] ?? '') ?></small><br>
                                <small class="text-muted"><?= date('d/m/Y H:i', strtotime($doc['data_upload'])) ?></small>
                            </div>
                            <div>
                                <a href="<?= htmlspecialchars($doc['path_file']) ?>" target="_blank" class="btn btn-sm btn-primary me-2">
                                    <i class="fas fa-download"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-danger delete-doc" data-id="<?= $doc['id'] ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- DOCUMENTI TEMPORANEI IN SESSIONE -->
                    <div id="documenti-temp-list">
                        <p class="text-muted">Nessun documento caricato.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
