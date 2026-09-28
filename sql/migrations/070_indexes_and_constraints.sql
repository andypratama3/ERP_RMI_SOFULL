-- 070_indexes_and_constraints.sql
-- Indexes, AUTO_INCREMENT, constraints

SET FOREIGN_KEY_CHECKS=0;

--
-- Indeks untuk tabel yang dibuang
--

--
-- Indeks untuk tabel `absensi_audit`
--
ALTER TABLE `absensi_audit`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `absensi_logs`
--
ALTER TABLE `absensi_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `absensi_offices`
--
ALTER TABLE `absensi_offices`
  ADD PRIMARY KEY (`office_code`);

--
-- Indeks untuk tabel `absensi_requests`
--
ALTER TABLE `absensi_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `absensi_settings`
--
ALTER TABLE `absensi_settings`
  ADD PRIMARY KEY (`k`);

--
-- Indeks untuk tabel `absensi_user_profile`
--
ALTER TABLE `absensi_user_profile`
  ADD PRIMARY KEY (`user_id`);

--
-- Indeks untuk tabel `erp_audit_log`
--
ALTER TABLE `erp_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_entity` (`entity_key`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indeks untuk tabel `fa_assets`
--
ALTER TABLE `fa_assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `asset_code` (`asset_code`);

--
-- Indeks untuk tabel `fa_audits`
--
ALTER TABLE `fa_audits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `audit_code` (`audit_code`);

--
-- Indeks untuk tabel `fa_audit_lines`
--
ALTER TABLE `fa_audit_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_asset` (`audit_id`,`asset_id`),
  ADD KEY `fk_al_asset` (`asset_id`);

--
-- Indeks untuk tabel `fa_audit_log`
--
ALTER TABLE `fa_audit_log`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `fa_dep_lines`
--
ALTER TABLE `fa_dep_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dep_asset_period` (`asset_id`,`period_ym`),
  ADD KEY `fk_dep_run` (`run_id`);

--
-- Indeks untuk tabel `fa_dep_runs`
--
ALTER TABLE `fa_dep_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dep_period` (`period_ym`);

--
-- Indeks untuk tabel `fa_disposals`
--
ALTER TABLE `fa_disposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ds_asset` (`asset_id`);

--
-- Indeks untuk tabel `fa_maintenance`
--
ALTER TABLE `fa_maintenance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_mt_asset` (`asset_id`);

--
-- Indeks untuk tabel `fa_transfers`
--
ALTER TABLE `fa_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_tr_asset` (`asset_id`);

--
-- Indeks untuk tabel `hrl_docs`
--
ALTER TABLE `hrl_docs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_doc_code` (`doc_code`),
  ADD KEY `ix_unit` (`unit`),
  ADD KEY `ix_category` (`category`),
  ADD KEY `ix_status` (`status`),
  ADD KEY `ix_deleted_at` (`deleted_at`);

--
-- Indeks untuk tabel `hrl_doc_acks`
--
ALTER TABLE `hrl_doc_acks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_ack` (`doc_id`,`version_no`,`username`),
  ADD KEY `ix_docver` (`doc_id`,`version_no`),
  ADD KEY `ix_user` (`username`);

--
-- Indeks untuk tabel `hrl_doc_versions`
--
ALTER TABLE `hrl_doc_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_doc_version` (`doc_id`,`version_no`),
  ADD KEY `ix_doc` (`doc_id`),
  ADD KEY `ix_status` (`status`),
  ADD KEY `ix_deleted_at` (`deleted_at`);

--
-- Indeks untuk tabel `master_company_bank_accounts`
--
ALTER TABLE `master_company_bank_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purpose` (`purpose`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_office` (`office_code`),
  ADD KEY `idx_bank` (`bank_name`),
  ADD KEY `idx_acc` (`account_number`);

--
-- Indeks untuk tabel `master_customers`
--
ALTER TABLE `master_customers`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_departements`
--
ALTER TABLE `master_departements`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_discount_policy`
--
ALTER TABLE `master_discount_policy`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dept_level_seg` (`department_code`,`level_name`,`segment`);

--
-- Indeks untuk tabel `master_emailcompany`
--
ALTER TABLE `master_emailcompany`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email_full` (`email_full`);

--
-- Indeks untuk tabel `master_employees`
--
ALTER TABLE `master_employees`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_manufactures`
--
ALTER TABLE `master_manufactures`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_mpr`
--
ALTER TABLE `master_mpr`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_mpr_customer` (`customer_id`);

--
-- Indeks untuk tabel `master_office`
--
ALTER TABLE `master_office`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `office_code` (`office_code`),
  ADD UNIQUE KEY `uq_office_code` (`office_code`);

--
-- Indeks untuk tabel `master_payment_terms`
--
ALTER TABLE `master_payment_terms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payment_terms_code` (`payment_terms_code`);

--
-- Indeks untuk tabel `master_pricelist`
--
ALTER TABLE `master_pricelist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sku` (`sku`),
  ADD KEY `idx_office` (`office_code`),
  ADD KEY `idx_customer` (`customers_code`),
  ADD KEY `idx_active` (`status`,`deleted_at`);

--
-- Indeks untuk tabel `master_products`
--
ALTER TABLE `master_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manufacture_id` (`manufacture_id`),
  ADD KEY `idx_vendor_id` (`vendor_id`);

--
-- Indeks untuk tabel `master_system_login`
--
ALTER TABLE `master_system_login`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_msl_status` (`status`),
  ADD KEY `idx_msl_role` (`role`),
  ADD KEY `idx_msl_office` (`office_code`);

--
-- Indeks untuk tabel `master_system_login_handover`
--
ALTER TABLE `master_system_login_handover`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_handover_username` (`username`),
  ADD KEY `idx_handover_changed_at` (`changed_at`);

--
-- Indeks untuk tabel `master_tax`
--
ALTER TABLE `master_tax`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tax_code` (`tax_code`);

--
-- Indeks untuk tabel `master_user`
--
ALTER TABLE `master_user`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_customer` (`customer_id`) USING BTREE;

--
-- Indeks untuk tabel `master_vendors`
--
ALTER TABLE `master_vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vendors_code` (`vendors_code`);

--
-- Indeks untuk tabel `payroll_employee_settings`
--
ALTER TABLE `payroll_employee_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_employee` (`employee_id`),
  ADD KEY `idx_login_user_id` (`login_user_id`);

--
-- Indeks untuk tabel `payroll_loans`
--
ALTER TABLE `payroll_loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_start` (`start_period_ym`),
  ADD KEY `idx_type` (`loan_type`);

--
-- Indeks untuk tabel `payroll_runs`
--
ALTER TABLE `payroll_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_period` (`period_ym`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `payroll_run_items`
--
ALTER TABLE `payroll_run_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_run_employee` (`run_id`,`employee_id`),
  ADD KEY `idx_run` (`run_id`),
  ADD KEY `idx_employee` (`employee_id`);

--
-- Indeks untuk tabel `payroll_salary_matrix`
--
ALTER TABLE `payroll_salary_matrix`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_matrix` (`matrix_year`,`payroll_status`,`payroll_level`),
  ADD KEY `idx_year` (`matrix_year`),
  ADD KEY `idx_status` (`payroll_status`);

--
-- Indeks untuk tabel `products_media`
--
ALTER TABLE `products_media`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `purchases_audit_log`
--
ALTER TABLE `purchases_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_ref` (`ref_code`);

--
-- Indeks untuk tabel `purchases_ceisa_payment`
--
ALTER TABLE `purchases_ceisa_payment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pib_pay_code` (`pay_code`),
  ADD KEY `idx_pib` (`pib_id`);

--
-- Indeks untuk tabel `purchases_ceisa_pib`
--
ALTER TABLE `purchases_ceisa_pib`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_po` (`po_id`),
  ADD KEY `idx_status` (`ceisa_status`);

--
-- Indeks untuk tabel `purchases_forwarder_invoice`
--
ALTER TABLE `purchases_forwarder_invoice`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fap_code` (`fap_code`),
  ADD KEY `idx_vendor` (`vendor_id`),
  ADD KEY `idx_po` (`po_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `purchases_forwarder_payment`
--
ALTER TABLE `purchases_forwarder_payment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fpay_code` (`pay_code`),
  ADD KEY `idx_fap` (`fap_id`);

--
-- Indeks untuk tabel `purchases_forwarder_quotes`
--
ALTER TABLE `purchases_forwarder_quotes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_po` (`po_id`),
  ADD KEY `idx_vendor` (`vendor_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `purchases_forwarding_docs`
--
ALTER TABLE `purchases_forwarding_docs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_po` (`po_id`),
  ADD KEY `idx_type` (`doc_type`);

--
-- Indeks untuk tabel `purchases_import_control`
--
ALTER TABLE `purchases_import_control`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_po` (`po_id`),
  ADD KEY `idx_prod_done` (`production_done_date`),
  ADD KEY `idx_eta` (`eta`);

--
-- Indeks untuk tabel `purchases_invoice_ap`
--
ALTER TABLE `purchases_invoice_ap`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ap_code` (`ap_code`);

--
-- Indeks untuk tabel `purchases_payment_ap`
--
ALTER TABLE `purchases_payment_ap`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pay_code` (`pay_code`),
  ADD KEY `idx_ap` (`ap_id`);

--
-- Indeks untuk tabel `purchases_po`
--
ALTER TABLE `purchases_po`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_po_code` (`po_code`);

--
-- Indeks untuk tabel `purchases_po_items`
--
ALTER TABLE `purchases_po_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_po` (`po_id`),
  ADD KEY `idx_product` (`product_id`);

--
-- Indeks untuk tabel `rbac_dept_role_permissions`
--
ALTER TABLE `rbac_dept_role_permissions`
  ADD PRIMARY KEY (`dept_code`,`role_code`,`perm_code`),
  ADD KEY `fk_rbac_perm` (`perm_code`);

--
-- Indeks untuk tabel `rbac_permissions`
--
ALTER TABLE `rbac_permissions`
  ADD PRIMARY KEY (`perm_code`);

--
-- Indeks untuk tabel `rbac_user_permissions`
--
ALTER TABLE `rbac_user_permissions`
  ADD PRIMARY KEY (`user_id`,`perm_code`),
  ADD KEY `fk_rbac_perm2` (`perm_code`);

--
-- Indeks untuk tabel `sales_do`
--
ALTER TABLE `sales_do`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_do_code` (`do_code`);

--
-- Indeks untuk tabel `sales_do_audit`
--
ALTER TABLE `sales_do_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_do_id` (`do_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indeks untuk tabel `sales_do_items`
--
ALTER TABLE `sales_do_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_do` (`do_id`);

--
-- Indeks untuk tabel `system_audit_logs`
--
ALTER TABLE `system_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_module_action` (`module`,`action`),
  ADD KEY `idx_record` (`record_table`,`record_id`),
  ADD KEY `idx_username` (`username`);

--
-- Indeks untuk tabel `system_config`
--
ALTER TABLE `system_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_key_office` (`config_key`,`office_code`);

--
-- Indeks untuk tabel `wqs_pr`
--
ALTER TABLE `wqs_pr`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pr_code` (`pr_code`);

--
-- Indeks untuk tabel `wqs_pr_items`
--
ALTER TABLE `wqs_pr_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pr` (`pr_id`),
  ADD KEY `idx_product` (`product_id`);

--
-- Indeks untuk tabel `wqs_stock`
--
ALTER TABLE `wqs_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product` (`product_id`);

--
-- Indeks untuk tabel `wqs_stock_adjustments`
--
ALTER TABLE `wqs_stock_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_code` (`adj_code`),
  ADD KEY `idx_pid` (`product_id`);

--
-- Indeks untuk tabel `wqs_stock_baseline_lock`
--
ALTER TABLE `wqs_stock_baseline_lock`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `wqs_stock_snapshot`
--
ALTER TABLE `wqs_stock_snapshot`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pid` (`product_id`),
  ADD KEY `idx_sku` (`sku`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `absensi_audit`
--
ALTER TABLE `absensi_audit`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `absensi_logs`
--
ALTER TABLE `absensi_logs`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `absensi_requests`
--
ALTER TABLE `absensi_requests`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `erp_audit_log`
--
ALTER TABLE `erp_audit_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_assets`
--
ALTER TABLE `fa_assets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_audits`
--
ALTER TABLE `fa_audits`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_audit_lines`
--
ALTER TABLE `fa_audit_lines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_audit_log`
--
ALTER TABLE `fa_audit_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_dep_lines`
--
ALTER TABLE `fa_dep_lines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_dep_runs`
--
ALTER TABLE `fa_dep_runs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_disposals`
--
ALTER TABLE `fa_disposals`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_maintenance`
--
ALTER TABLE `fa_maintenance`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_transfers`
--
ALTER TABLE `fa_transfers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_docs`
--
ALTER TABLE `hrl_docs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `hrl_doc_acks`
--
ALTER TABLE `hrl_doc_acks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_doc_versions`
--
ALTER TABLE `hrl_doc_versions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `master_company_bank_accounts`
--
ALTER TABLE `master_company_bank_accounts`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_customers`
--
ALTER TABLE `master_customers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT untuk tabel `master_departements`
--
ALTER TABLE `master_departements`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1153;

--
-- AUTO_INCREMENT untuk tabel `master_discount_policy`
--
ALTER TABLE `master_discount_policy`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_emailcompany`
--
ALTER TABLE `master_emailcompany`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `master_employees`
--
ALTER TABLE `master_employees`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `master_manufactures`
--
ALTER TABLE `master_manufactures`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `master_mpr`
--
ALTER TABLE `master_mpr`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_office`
--
ALTER TABLE `master_office`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `master_payment_terms`
--
ALTER TABLE `master_payment_terms`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `master_pricelist`
--
ALTER TABLE `master_pricelist`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_products`
--
ALTER TABLE `master_products`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `master_system_login`
--
ALTER TABLE `master_system_login`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `master_system_login_handover`
--
ALTER TABLE `master_system_login_handover`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_tax`
--
ALTER TABLE `master_tax`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `master_user`
--
ALTER TABLE `master_user`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_vendors`
--
ALTER TABLE `master_vendors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `payroll_employee_settings`
--
ALTER TABLE `payroll_employee_settings`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_loans`
--
ALTER TABLE `payroll_loans`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_runs`
--
ALTER TABLE `payroll_runs`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_run_items`
--
ALTER TABLE `payroll_run_items`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_salary_matrix`
--
ALTER TABLE `payroll_salary_matrix`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `products_media`
--
ALTER TABLE `products_media`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_audit_log`
--
ALTER TABLE `purchases_audit_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT untuk tabel `purchases_ceisa_payment`
--
ALTER TABLE `purchases_ceisa_payment`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_ceisa_pib`
--
ALTER TABLE `purchases_ceisa_pib`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarder_invoice`
--
ALTER TABLE `purchases_forwarder_invoice`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarder_payment`
--
ALTER TABLE `purchases_forwarder_payment`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarder_quotes`
--
ALTER TABLE `purchases_forwarder_quotes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarding_docs`
--
ALTER TABLE `purchases_forwarding_docs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_import_control`
--
ALTER TABLE `purchases_import_control`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `purchases_invoice_ap`
--
ALTER TABLE `purchases_invoice_ap`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_payment_ap`
--
ALTER TABLE `purchases_payment_ap`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_po`
--
ALTER TABLE `purchases_po`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_po_items`
--
ALTER TABLE `purchases_po_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `sales_do`
--
ALTER TABLE `sales_do`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT untuk tabel `sales_do_audit`
--
ALTER TABLE `sales_do_audit`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `sales_do_items`
--
ALTER TABLE `sales_do_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `system_audit_logs`
--
ALTER TABLE `system_audit_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `system_config`
--
ALTER TABLE `system_config`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT untuk tabel `wqs_pr`
--
ALTER TABLE `wqs_pr`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `wqs_pr_items`
--
ALTER TABLE `wqs_pr_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock`
--
ALTER TABLE `wqs_stock`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_adjustments`
--
ALTER TABLE `wqs_stock_adjustments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_snapshot`
--
ALTER TABLE `wqs_stock_snapshot`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `fa_audit_lines`
--
ALTER TABLE `fa_audit_lines`
  ADD CONSTRAINT `fk_al_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_al_audit` FOREIGN KEY (`audit_id`) REFERENCES `fa_audits` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `fa_dep_lines`
--
ALTER TABLE `fa_dep_lines`
  ADD CONSTRAINT `fk_dep_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dep_run` FOREIGN KEY (`run_id`) REFERENCES `fa_dep_runs` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `fa_disposals`
--
ALTER TABLE `fa_disposals`
  ADD CONSTRAINT `fk_ds_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `fa_maintenance`
--
ALTER TABLE `fa_maintenance`
  ADD CONSTRAINT `fk_mt_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `fa_transfers`
--
ALTER TABLE `fa_transfers`
  ADD CONSTRAINT `fk_tr_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `master_user`
--
ALTER TABLE `master_user`
  ADD CONSTRAINT `fk_mpr_customer` FOREIGN KEY (`customer_id`) REFERENCES `master_customers` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `rbac_dept_role_permissions`
--
ALTER TABLE `rbac_dept_role_permissions`
  ADD CONSTRAINT `fk_rbac_perm` FOREIGN KEY (`perm_code`) REFERENCES `rbac_permissions` (`perm_code`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `rbac_user_permissions`
--
ALTER TABLE `rbac_user_permissions`
  ADD CONSTRAINT `fk_rbac_perm2` FOREIGN KEY (`perm_code`) REFERENCES `rbac_permissions` (`perm_code`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

SET FOREIGN_KEY_CHECKS=1;
