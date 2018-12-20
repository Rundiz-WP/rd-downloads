<?php
/**
 * Bulk actions class.
 * 
 * @package rd-downloads
 */


namespace RdDownloads\App\Controllers\Admin\Downloads\Xhr;

if (!class_exists('\\RdDownloads\\App\\Controllers\\Admin\\Downloads\\Xhr\\XhrBulkActions')) {
    class XhrBulkActions extends \RdDownloads\App\Controllers\XhrBased implements \RdDownloads\App\Controllers\ControllerInterface
    {


        /**
         * Get the selected bulk action and process to the selected items.
         */
        public function bulkActions()
        {
            $this->commonAccessCheck(['post'], ['rd-downloads_ajax-manage-nonce', 'security']);

            // check the most basic capability (permission).
            if (!current_user_can('upload_files')) {
                $output['form_result_class'] = 'notice-error';
                $output['form_result_msg'] = __('You do not have permission to access this page.');
                wp_send_json($output, 403);
            }

            $bulkAction = filter_input(INPUT_POST, 'bulkAction', FILTER_SANITIZE_STRING);
            $download_ids = filter_input_array(INPUT_POST, [
                'download_id' => [
                    'filter' => FILTER_SANITIZE_NUMBER_INT,
                    'flags' => FILTER_REQUIRE_ARRAY,
                ],
            ]);
            if (is_array($download_ids) && array_key_exists('download_id', $download_ids)) {
                $download_ids = $download_ids['download_id'];
            }

            switch ($bulkAction) {
                case 'githubUpdate':
                    return $this->githubUpdate($download_ids);
                case 'remoteUpdate':
                    return $this->remoteUpdate($download_ids);
                case 'delete':
                    return $this->deleteDownloads($download_ids);
                case 'clearlogs':
                    return $this->clearLogs();
            }// endswitch;
            unset($bulkAction);

            $output['form_result_class'] = 'notice-error';
            $output['form_result_msg'] = __('Invalid form action, please try again.', 'rd-downloads');
            wp_send_json($output, 400);
        }// bulkActions


        /**
         * Clear the logs.
         */
        public function clearLogs()
        {
            // check the most basic capability (permission).
            if (!current_user_can('delete_users')) {
                $output['form_result_class'] = 'notice-error';
                $output['form_result_msg'] = __('You do not have permission to access this page.');
                wp_send_json($output, 403);
            }

            $responseStatus = 200;
            $output = [];

            $RdDownloadLogs = new \RdDownloads\App\Models\RdDownloadLogs();
            $clearResult = $RdDownloadLogs->clearLogs();
            unset($RdDownloadLogs);

            if (isset($clearResult['delete_error'])) {
                $output['form_result_class'] = 'notice-error';
                $output['form_result_msg'] = $clearResult['delete_error'];
            } else {
                $output['form_result_class'] = 'notice-success';
                $output['form_result_msg'] = __('All logs were cleared.', 'rd-downloads');
            }

            wp_send_json($output, $responseStatus);
        }// clearLogs


        /**
         * Perform delete downloads data.
         * 
         * @global \wpdb $wpdb
         * @param array $download_ids The `download_id` values in one array. Example: `array(1, 2, 4, 5, 6);`
         */
        protected function deleteDownloads(array $download_ids)
        {
            $responseStatus = 200;
            $output = [];

            // get the data from DB.
            global $wpdb;
            // use WHERE IN to search for *any* of the values. https://mariadb.com/kb/en/library/in/
            $sql = 'SELECT `download_id`, `user_id`, `download_name`, `download_type`, `download_related_path`
                FROM `' . $wpdb->prefix . 'rd_downloads`
                WHERE `download_id` IN (' . implode(', ', array_fill(0, count($download_ids), '%d')) . ')';// https://stackoverflow.com/a/10634225/128761
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    $sql,
                    $download_ids
                )
            );

            if (defined('WP_DEBUG') && WP_DEBUG === true) {
                $output['debugSQL'] = $sql;// before executed sql statement (contain %s placeholder for prepare).
                $output['debugLastQuery'] = $wpdb->last_query;// executed sql statement.
                $output['download_ids'] = $download_ids;
            }
            unset($sql);

            if (count($results) > 0 && (is_array($results) || is_object($results))) {
                // if found the results.
                $current_user_id = get_current_user_id();

                $found_download_ids = [];
                $capability_limited_download_ids = [];
                $capability_limited_download_names = [];
                $deleted_download_ids = [];
                $deleted_download_names = [];
                $failed_delete_download_ids = [];
                $failed_delete_download_names = [];

                $FileSystem = new \RdDownloads\App\Libraries\FileSystem();

                foreach ($results as $row) {
                    $found_download_ids[] = $row->download_id;
                    if ($row->user_id != $current_user_id && !current_user_can('edit_others_posts')) {
                        // this user is trying to editing/updating others download and don't have capability to do it.
                        // this condition is unable to delete the data.
                        $capability_limited_download_ids[] = $row->download_id;
                        $capability_limited_download_names[] = $row->download_name;
                    } else {
                        // this condition is able to delete the data.
                        if ($row->download_type == '0' && stripos($row->download_related_path, 'rd-downloads/') !== false) {
                            // if local file.
                            // check again that this file is NOT linked with other downloads data.
                            $sql = 'SELECT COUNT(`download_id`) AS `total`, `download_id`, `download_related_path` FROM `' . $wpdb->prefix . 'rd_downloads` WHERE `download_related_path` = %s AND `download_id` != %d';
                            $checkExists = $wpdb->get_var($wpdb->prepare($sql, [$row->download_related_path, $row->download_id]));
                            unset($sql);
                            if (is_null($checkExists)) {
                                // if `get_var()` contain some error.
                                $failed_delete_download_ids[] = $row->download_id;
                                $failed_delete_download_names[] = $row->download_name;
                                $donot_delete = true;
                                error_log(
                                    sprintf(
                                        /* translators: %1$s: The last query statement, %2$s: MySQL error message. */
                                        __('An error has been occur in SQL statement (%1$s). The error message: %2$s .'),
                                        $wpdb->last_query,
                                        $wpdb->last_error
                                    )
                                );
                            } elseif ($checkExists <= 0) {
                                // if not exists in other download data, delete the file.
                                $wp_upload_dir = wp_upload_dir();
                                if (isset($wp_upload_dir['basedir'])) {
                                    $FileSystem->deleteFile(trailingslashit($wp_upload_dir['basedir']) . $row->download_related_path);
                                }
                                unset($wp_upload_dir);
                            }
                            unset($checkExists);
                        }// endif local file.

                        if (!isset($donot_delete) || (isset($donot_delete) && $donot_delete === false)) {
                            // if it is able to delete, delete it in db.
                            $deleteResult = $wpdb->delete($wpdb->prefix . 'rd_downloads', ['download_id' => $row->download_id]);
                            if ($deleteResult !== false) {
                                $deleted_download_ids[] = $row->download_id;
                                $deleted_download_names[] = $row->download_name;
                                $Dll = new \RdDownloads\App\Models\RdDownloadLogs();
                                $Dll->writeLog('admin_delete', [
                                    'download_id' => $row->download_id,
                                ]);
                                unset($Dll);
                            } else {
                                $failed_delete_download_ids[] = $row->download_id;
                                $failed_delete_download_names[] = $row->download_name;
                            }
                        }
                        unset($donot_delete);
                    }
                }// endforeach;
                unset($current_user_id, $FileSystem, $row);

                // check deleted, failed, result and set the error message.
                if (count($download_ids) === count($deleted_download_ids)) {
                    $output['form_result_class'] = 'notice-success';
                    $output['form_result_msg'] = __('Success! All selected items have been deleted.', 'rd-downloads');
                } else {
                    $notfound_download_ids = array_diff($download_ids, $found_download_ids);

                    $output['form_result_class'] = 'notice-warning';
                    $output['form_result_msg'] = '<p><strong>' . __('Warning! There are some problem about delete the items, here are the results.', 'rd-downloads') . '</strong></p>' .
                        '<ul class="rd-downloads-ul">' .
                            (count($deleted_download_names) > 0 ? '<li><strong>' . _n('Deleted item', 'Deleted items', count($deleted_download_names), 'rd-downloads') . ':</strong> ' . implode(', ', $deleted_download_names) . '</li>' : '') .
                            (count($failed_delete_download_names) > 0 ? '<li><strong>' . _n('Failed to delete item', 'Failed to delete items', count($failed_delete_download_names), 'rd-downloads') . ':</strong> ' . implode(', ', $failed_delete_download_names) . '</li>' : '') .
                            (count($capability_limited_download_names) > 0 ? '<li><strong>' . _n('Capability limited item', 'Capability limited items', count($capability_limited_download_names), 'rd-downloads') . ':</strong> ' . implode(', ', $capability_limited_download_names) . '</li>' : '') .
                            (count($notfound_download_ids) > 0 ? '<li><strong>' .  _n('Mismatch ID', 'Mismatch IDs', count($notfound_download_ids), 'rd-downloads') . ':</strong> ' . implode(', ', $notfound_download_ids) . '</li>' : '') .
                        '</ul>';
                }

                // set additional result.
                $output['additionalResults'] = [
                    'found_download_ids' => $found_download_ids,
                    'capability_limited_download_ids' => $capability_limited_download_ids,
                    'deleted_download_ids' => $deleted_download_ids,
                    'failed_delete_download_ids' => $failed_delete_download_ids,
                    'notfound_download_ids' => (isset($notfound_download_ids) ? $notfound_download_ids : []),
                ];

                unset($capability_limited_download_ids, $capability_limited_download_names);
                unset($failed_delete_download_ids, $failed_delete_download_names);
                unset($found_download_ids, $notfound_download_ids);
                unset($deleted_download_ids, $deleted_download_names);
            } else {
                $responseStatus = 404;
                $output['form_result_class'] = 'notice-error';
                $output['form_result_msg'] = __('The selected items was not found.', 'rd-downloads');
            }
            unset($results);

            wp_send_json($output, $responseStatus);
        }// deleteDownloads


        /**
         * Perform get GitHub repository data and then update the file size, URL.
         * 
         * @global \wpdb $wpdb
         * @param array $download_ids The `download_id` values in one array. Example: `array(1, 2, 4, 5, 6);`
         */
        protected function githubUpdate(array $download_ids)
        {
            $responseStatus = 200;
            $output = [];

            // get the data from DB.
            global $wpdb;
            // use WHERE IN to search for *any* of the values. https://mariadb.com/kb/en/library/in/
            $sql = 'SELECT `download_id`, `user_id`, `download_name`, `download_type`, `download_github_name`, `download_url`
                FROM `' . $wpdb->prefix . 'rd_downloads`
                WHERE `download_id` IN (' . implode(', ', array_fill(0, count($download_ids), '%d')) . ')';// https://stackoverflow.com/a/10634225/128761
            $sql .= ' AND `download_type` = 1';
            $sql .= ' AND `download_github_name` IS NOT NULL';
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    $sql,
                    $download_ids
                )
            );

            if (defined('WP_DEBUG') && WP_DEBUG === true) {
                $output['debugSQL'] = $sql;// before executed sql statement (contain %s placeholder for prepare).
                $output['debugLastQuery'] = $wpdb->last_query;// executed sql statement.
                $output['download_ids'] = $download_ids;
            }
            unset($sql);

            if (count($results) > 0 && (is_array($results) || is_object($results))) {
                // if found the results.
                $current_user_id = get_current_user_id();

                $found_download_ids = [];
                $capability_limited_download_ids = [];
                $capability_limited_download_names = [];
                $updated_download_ids = [];
                $updated_download_names = [];
                $failed_update_download_ids = [];
                $failed_update_download_names = [];

                $Github = new \RdDownloads\App\Libraries\Github();
                $FileSystem = new \RdDownloads\App\Libraries\FileSystem();

                foreach ($results as $row) {
                    $found_download_ids[] = $row->download_id;
                    if ($row->user_id != $current_user_id && !current_user_can('edit_others_posts')) {
                        // this user is trying to editing/updating others download and don't have capability to do it.
                        // this condition is unable to update the data.
                        $capability_limited_download_ids[] = $row->download_id;
                        $capability_limited_download_names[] = $row->download_name;
                    } else {
                        // this condition is able to update the data.
                        $githubResult = $Github->getLatestRepositoryData($row->download_url);
                        if ($githubResult !== false) {
                            // prepare update data.
                            $data = [];
                            $data['download_url'] = (isset($githubResult['url']) ? $githubResult['url'] : $row->download_url);
                            $data['download_github_name'] = (isset($githubResult['nameWithOwner']) ? $githubResult['nameWithOwner'] : $row->download_github_name);
                            $data['download_size'] = (isset($githubResult['size']) && $githubResult['size'] >= '0' ? $githubResult['size'] : '0');
                            $fileParts = $FileSystem->getFilePart($data['download_url']);
                            if (isset($fileParts['nameext'])) {
                                $data['download_file_name'] = $fileParts['nameext'];
                            }
                            unset($fileParts);
                            $data['download_update'] = current_time('mysql');
                            $data['download_update_gmt'] = current_time('mysql', true);

                            $updateResult = $wpdb->update($wpdb->prefix . 'rd_downloads', $data, ['download_id' => $row->download_id]);
                            if ($updateResult !== false) {
                                $updated_download_ids[] = $row->download_id;
                                $updated_download_names[] = $row->download_name;
                            } else {
                                $failed_update_download_ids[] = $row->download_id;
                                $failed_update_download_names[] = $row->download_name;
                            }
                            unset($data);
                        } else {
                            $failed_update_download_ids[] = $row->download_id;
                            $failed_update_download_names[] = $row->download_name;
                        }
                        unset($githubResult);
                    }
                }// endforeach;
                unset($current_user_id, $Github, $FileSystem, $row);

                // check updated, failed, result and set the error message.
                if (count($download_ids) === count($updated_download_ids)) {
                    $output['form_result_class'] = 'notice-success';
                    $output['form_result_msg'] = __('Success! All selected items have been updated.', 'rd-downloads');
                } else {
                    $notfound_download_ids = array_diff($download_ids, $found_download_ids);

                    $output['form_result_class'] = 'notice-warning';
                    $output['form_result_msg'] = '<p><strong>' . __('Warning! There are some problem about update the items, here are the results.', 'rd-downloads') . '</strong></p>' .
                        '<ul class="rd-downloads-ul">' .
                            (count($updated_download_names) > 0 ? '<li><strong>' . _n('Updated item', 'Updated items', count($updated_download_names), 'rd-downloads') . ':</strong> ' . implode(', ', $updated_download_names) . '</li>' : '') .
                            (count($failed_update_download_names) > 0 ? '<li><strong>' . _n('Failed to update item', 'Failed to update items', count($failed_update_download_names), 'rd-downloads') . ':</strong> ' . implode(', ', $failed_update_download_names) . '</li>' : '') .
                            (count($capability_limited_download_names) > 0 ? '<li><strong>' . _n('Capability limited item', 'Capability limited items', count($capability_limited_download_names), 'rd-downloads') . ':</strong> ' . implode(', ', $capability_limited_download_names) . '</li>' : '') .
                            (count($notfound_download_ids) > 0 ? '<li><strong>' .  _n('Mismatch ID', 'Mismatch IDs', count($notfound_download_ids), 'rd-downloads') . ':</strong> ' . implode(', ', $notfound_download_ids) . '</li>' : '') .
                        '</ul>';
                }

                // set additional result.
                $output['additionalResults'] = [
                    'found_download_ids' => $found_download_ids,
                    'capability_limited_download_ids' => $capability_limited_download_ids,
                    'updated_download_ids' => $updated_download_ids,
                    'failed_update_download_ids' => $failed_update_download_ids,
                    'notfound_download_ids' => (isset($notfound_download_ids) ? $notfound_download_ids : []),
                ];

                unset($capability_limited_download_ids, $capability_limited_download_names);
                unset($failed_update_download_ids, $failed_update_download_names);
                unset($found_download_ids, $notfound_download_ids);
                unset($updated_download_ids, $updated_download_names);
            } else {
                $responseStatus = 404;
                $output['form_result_class'] = 'notice-error';
                $output['form_result_msg'] = __('The selected items was not found or not matched download type.', 'rd-downloads');
            }
            unset($results);

            wp_send_json($output, $responseStatus);
        }// githubUpdate


        /**
         * {@inheritDoc}
         */
        public function registerHooks()
        {
            if (is_admin()) {
                add_action('wp_ajax_RdDownloadsBulkActions', [$this, 'bulkActions']);
            }
        }// registerHooks


        /**
         * Perform get remote file data and then update the file size.
         * 
         * @global \wpdb $wpdb
         * @param array $download_ids The `download_id` values in one array. Example: `array(1, 2, 4, 5, 6);`
         */
        protected function remoteUpdate(array $download_ids)
        {
            $responseStatus = 200;
            $output = [];

            // get the data from DB.
            global $wpdb;
            // use WHERE IN to search for *any* of the values. https://mariadb.com/kb/en/library/in/
            $sql = 'SELECT `download_id`, `user_id`, `download_name`, `download_type`, `download_url`
                FROM `' . $wpdb->prefix . 'rd_downloads`
                WHERE `download_id` IN (' . implode(', ', array_fill(0, count($download_ids), '%d')) . ')';// https://stackoverflow.com/a/10634225/128761
            $sql .= ' AND `download_type` = 2';
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    $sql,
                    $download_ids
                )
            );

            if (defined('WP_DEBUG') && WP_DEBUG === true) {
                $output['debugSQL'] = $sql;// before executed sql statement (contain %s placeholder for prepare).
                $output['debugLastQuery'] = $wpdb->last_query;// executed sql statement.
                $output['download_ids'] = $download_ids;
            }
            unset($sql);

            if (count($results) > 0 && (is_array($results) || is_object($results))) {
                // if found the results.
                $current_user_id = get_current_user_id();

                $found_download_ids = [];
                $capability_limited_download_ids = [];
                $capability_limited_download_names = [];
                $updated_download_ids = [];
                $updated_download_names = [];
                $failed_update_download_ids = [];
                $failed_update_download_names = [];

                $Url = new \RdDownloads\App\Libraries\Url();

                foreach ($results as $row) {
                    $found_download_ids[] = $row->download_id;
                    if ($row->user_id != $current_user_id && !current_user_can('edit_others_posts')) {
                        // this user is trying to editing/updating others download and don't have capability to do it.
                        // this condition is unable to update the data.
                        $capability_limited_download_ids[] = $row->download_id;
                        $capability_limited_download_names[] = $row->download_name;
                    } else {
                        // this condition is able to update the data.
                        $remoteFileResult = $Url->getRemoteFileInfo($row->download_url);
                        if ($remoteFileResult !== false) {
                            // prepare update data.
                            $data = [];
                            $data['download_size'] = (isset($remoteFileResult['size']) && $remoteFileResult['size'] >= '0' ? $remoteFileResult['size'] : '0');
                            $data['download_update'] = current_time('mysql');
                            $data['download_update_gmt'] = current_time('mysql', true);

                            $updateResult = $wpdb->update($wpdb->prefix . 'rd_downloads', $data, ['download_id' => $row->download_id]);
                            if ($updateResult !== false) {
                                $updated_download_ids[] = $row->download_id;
                                $updated_download_names[] = $row->download_name;
                            } else {
                                $failed_update_download_ids[] = $row->download_id;
                                $failed_update_download_names[] = $row->download_name;
                            }
                            unset($data);
                        } else {
                            $failed_update_download_ids[] = $row->download_id;
                            $failed_update_download_names[] = $row->download_name;
                        }
                        unset($remoteFileResult);
                    }
                }// endforeach;
                unset($current_user_id, $row, $Url);

                // check updated, failed, result and set the error message.
                if (count($download_ids) === count($updated_download_ids)) {
                    $output['form_result_class'] = 'notice-success';
                    $output['form_result_msg'] = __('Success! All selected items have been updated.', 'rd-downloads');
                } else {
                    $notfound_download_ids = array_diff($download_ids, $found_download_ids);

                    $output['form_result_class'] = 'notice-warning';
                    $output['form_result_msg'] = '<p><strong>' . __('Warning! There are some problem about update the items, here are the results.', 'rd-downloads') . '</strong></p>' .
                        '<ul class="rd-downloads-ul">' .
                            (count($updated_download_names) > 0 ? '<li><strong>' . _n('Updated item', 'Updated items', count($updated_download_names), 'rd-downloads') . ':</strong> ' . implode(', ', $updated_download_names) . '</li>' : '') .
                            (count($failed_update_download_names) > 0 ? '<li><strong>' . _n('Failed to update item', 'Failed to update items', count($failed_update_download_names), 'rd-downloads') . ':</strong> ' . implode(', ', $failed_update_download_names) . '</li>' : '') .
                            (count($capability_limited_download_names) > 0 ? '<li><strong>' . _n('Capability limited item', 'Capability limited items', count($capability_limited_download_names), 'rd-downloads') . ':</strong> ' . implode(', ', $capability_limited_download_names) . '</li>' : '') .
                            (count($notfound_download_ids) > 0 ? '<li><strong>' .  _n('Mismatch ID', 'Mismatch IDs', count($notfound_download_ids), 'rd-downloads') . ':</strong> ' . implode(', ', $notfound_download_ids) . '</li>' : '') .
                        '</ul>';
                }

                // set additional result.
                $output['additionalResults'] = [
                    'found_download_ids' => $found_download_ids,
                    'capability_limited_download_ids' => $capability_limited_download_ids,
                    'updated_download_ids' => $updated_download_ids,
                    'failed_update_download_ids' => $failed_update_download_ids,
                    'notfound_download_ids' => (isset($notfound_download_ids) ? $notfound_download_ids : []),
                ];

                unset($capability_limited_download_ids, $capability_limited_download_names);
                unset($failed_update_download_ids, $failed_update_download_names);
                unset($found_download_ids, $notfound_download_ids);
                unset($updated_download_ids, $updated_download_names);
            } else {
                $responseStatus = 404;
                $output['form_result_class'] = 'notice-error';
                $output['form_result_msg'] = __('The selected items was not found or not matched download type.', 'rd-downloads');
            }
            unset($results);

            wp_send_json($output, $responseStatus);
        }// remoteUpdate


    }
}