<?php
return [
    // general 
    'unexpected_error' => 'An unexpected error occurred',
    'validation_failed' => 'Validation failed',
    'something_wrong' => 'Something went wrong, please try again later',
    'duplicate_email' => 'email is already taken',
    'retrieve_data_error' => 'An error occurred while loading the data. Please try again later',
    'create_data_error' => 'An error occurred while saving the data. Please try again later',
    'update_data_error' => 'An error occurred while updating the data. Please try again later',
    'delete_data_error' => 'Failed to delete data.',
    'restore_data_error' => 'An error occurred while restoring the data. Please try again later',


    // Authentication
    'registration_success' => 'Done, move to sending OTP code page',
    'otp_sent' => 'An OTP code was sent to your email',
    'otp_invalid' => 'Invalid OTP code or email',
    'otp_verified' => 'OTP verified successfully. Your account is activated.',
    'login_error' => 'Login error',
    'invalid_credentials' => 'The email or password you entered is incorrect',
    'logout_success' => 'Logged out',
    'logout_failed' => 'Logout failed',
    'expired_token' =>'you need to login',
    'old_password_mismatch' => 'Old password does not match',
    'password_updated' => 'Password successfully updated',
    'reset_link_sent' => 'Reset link sent successfully',
    'reset_link_failed' => 'Failed to send reset link',
    'reset_failed' => 'Password Reset failed',


    'reset_code_sent' => 'A password reset code has been sent to your email',
    'reset_code_failed' => 'Failed to send reset code',
    'validation_failed' => 'Validation failed',
    'invalid_token' => 'The reset code is incorrect',
    'password_reset_success' => 'Your password has been reset successfully',
    'reset_failed' => 'Failed to reset the password',

    // special tasks
   
    'load_completed_tasks_failed' => 'Failed to load completed tasks',
    'load_ongoing_tasks_failed' => 'Failed to load ongoing tasks',
    'load_canceled_tasks_failed' => 'Failed to load canceled tasks',
    'task_canceled' => 'Special task is canceled',
    'task_cancel_failed' => 'Failed to cancel special task',
    'task_created' => 'Special task was created successfully',
    'task_create_failed' => 'Failed to create special task',
    'task_not_found' => 'Special task not found',
    'task_restored' => 'Special task is restored',
    'can_not_complete_special_task' => 'special task can not be completed until all subtasks are',
    'task_completed' => 'task is completed',
    'task_transfered'=>'task was transfered successfully',

    // update profile
    'profile_updated' => 'personal information were updated successfully',


    // employee tasks
    'failed_to_load_tasks' => 'Failed to load tasks',
    'employee_task_not_found' => 'Employee task not found',
    'employee_task_canceled' => 'Employee task has been canceled',
    'employee_task_created_successfully' => 'Employee task was created successfully',
    'failed_to_cancel_task' => 'Failed to cancel the task',
    'failed_to_create_task' => 'Failed to create employee task',
    'failed_to_fetch_task_details' => 'Failed to fetch task details',
    'employee_task_restored' => 'Employee task is restored',
    'failed_to_restore_task' => 'Failed to restore task',
    'employee_task_updated_successfully' =>'employee task was updated successfully',
    'can_not_complete_employee_task' => 'employee task can not be completed until all subtasks are',

    'employee_images_updated'=>'employee images of employee task was updated successfully',
    'employee_sub_task_images_updated'=>'employee images of employee sub task was updated successfully',

    'employee_image_required'=>'Employee image must be uploaded before finishing the task',
    'unauthorized'      => 'You are not authorized to perform this action',
    'task_not_found'    => 'The requested task was not found',
    'invalid_task_type' => 'Invalid task type',
    // employees
    'employee_created_successfully' => 'A new employee was created successfully',
    'employee_updated_successfully' => 'Employee information updated successfully',
    'employee_not_found' => 'Employee not found',
    'failed_to_create_employee' => 'Failed to create employee',
    'failed_to_update_employee' => 'Failed to update employee',
    'arrival_time' => 'Arrival time recorded',
    'departure_time' => 'Departure time recorded',
    'already_scanned' => 'Already scanned in and out today',
    'salary_paid' => 'Salary was paid successfully',
    'points_updated' => 'employee points were updated successfully',
    //projects
    'project_created_successfully' => 'Project created successfully',
    'project_details_loaded' => 'Project details loaded successfully',
    'project_not_found' => 'Project not found',
    'failed_to_create_project' => 'Failed to create project',
    'failed_to_load_project_details' => 'Failed to load project details',
    'ongoing_projects_loaded' => 'Ongoing projects retrieved successfully',
    'failed_to_load_ongoing_projects' => 'Failed to load ongoing projects',
    'completed_projects_loaded' => 'Completed projects retrieved successfully',
    'failed_to_load_completed_projects' => 'Failed to load completed projects',
    'validation_failed' => 'Validation failed',
    'something_wrong' => 'Something went wrong. Please try again',
    'project_already_has_product'=>'Project already has this product',
    'product_added_to_project'=>'Product was added to project successfully',
    'project_updated'=>'Project was updated successfully',
    'project_completed'=>'project was completed successfully',
    'cannot_add_share_or_percentage'=>'can not add share or percentage if no partner is selected',
    // customers
    'created_customer_successfully' => 'Customer was created successfully',
    'created_seller_successfully' => 'Seller was created successfully',

    'customer_deleted_successfully' => 'Customer was successfully deleted',
    'customer_not_found' => 'Customer not found',
    'failed_to_create_customer' => 'Failed to create the customer',
    'failed_to_delete_customer' => 'Failed to delete the customer',
    'failed_to_load_customer_details' => 'Failed to load customer details',
    'failed_to_load_customers' => 'Failed to load customers list',
    'failed_to_restore_customer' => 'Failed to restore customer',
    'customer_restored_successfully' =>'Customer was successfully restored',
    'person_updated'=>'Person was updated successfully',
    'cannot_delete_person'=>'Can\'t delete the person for having financials',
    'person_deleted'=>'Person was successfully deleted',
    'cannot_delete_person_for_check' => 'Can\'t delete person for having uncashed incoming check',
    //follow up
    'followup_created_successfully'    => 'Followup created successfully',
    'followup_canceled_successfully'   => 'Followup has been canceled',
    'failed_to_create_followup'        => 'Failed to create the followup',
    'failed_to_cancel_followup'        => 'Failed to cancel the followup',
    'failed_to_load_followups'         => 'Failed to load followups list',
    'followup_not_found'               => 'Followup not found',
    'followup_restored_successfully' => 'Followup restored successfully',
    'failed_to_restore_followup' => 'Failed to restore followup',
    'followup_updated' => 'followup updated successfully',
    // goals
    'goal_created_successfully'     => 'Goal created successfully',
    'goal_cancelled_successfully'    => 'Goal has been cancelled successfully',
    'goal_transferred_successfully'    => 'Goal has been transferred successfully',
    'goal_updated_successfully'=>'Goal was updated successfully',
    'failed_to_create_goal'         => 'Failed to create the goal',
    'failed_to_cancel_goal'         => 'Failed to cancel the goal',
    'failed_to_load_goal'           => 'Failed to load goal details',
    'failed_to_load_goals'          => 'Failed to load goals list',
    'goal_not_found'                => 'Goal not found',
    'goal_restored_successfully' => 'Goal restored successfully',
    'failed_to_restore_goal' => 'Failed to restore goal',
    'one_is_required' =>'product id or customer id or employee id is required',
    'goal_updated'=>'goal was updated successfully',
    'goal_deleted'=>'Goal was deleted successfully',
    // debts
    'debt_created_successfully' => 'Debt created successfully',
    'debt_updated_successfully' => 'Debt updated successfully',
    'failed_to_create_debt'     => 'Failed to create the debt',
    'failed_to_update_debt'     => 'Failed to update the debt',
    'failed_to_load_debt'       => 'Failed to load the debt details',
    'failed_to_load_debts'      => 'Failed to load debts',
    'debt_not_found'            => 'Debt not found',

    //deposit
    'deposit_created_successfully' => 'deposit created successfully',
    'failed_to_creat_deposit' => 'failed to create deposit',
   //draw
    'draw_created_successfully' => 'draw created successfully',
    'failed_to_creat_draw' => 'failed to create draw',

    //maintenance
    'failed_to_load_maintenances' => 'failed to load maintenance data',
    'maintenance_created_successfully' => 'maintenance created successfully',
    'maintenance_not_found'=>'maintenance was not found',
    'maintenance_updated_successfully'=>'maintenance was updated successfully',

    // Boxes
    'box_created_successfully'     => 'Box created successfully.',
    'box_updated_successfully'     => 'Box updated successfully.',
    'box_not_found'                => 'Box not found.',
    'validation_failed'            => 'Validation failed.',
    'retrieve_data_error'          => 'Failed to retrieve data from the database.',
    'failed_to_create_box'         => 'Failed to create the box.',
    'failed_to_update_box'         => 'Failed to update the box.',
    'failed_to_load_box'           => 'Failed to load box details.',
    'failed_to_load_boxes'         => 'Failed to load boxes.',
    'drawer_boxes_not_found'       => 'No drawer boxes found.',
    'bank_account_boxes_not_found' => 'No bank account boxes found.',
    'safe_boxes_not_found'         => 'No safe boxes found.',
    'balance_transfered' => 'Balance was transfered successfully',
    'not_enough' => 'Transfered balance is higher than box\'s balance',
    'cannot_transfer_same_box' =>'Cannot transfer between the same box',
    'box_balance_added_successfully' => 'Box balance was added successfully',
    'box_balance_deduct_successfully' => 'Box balance was deduct',
    'box_deleted' => 'Box was deleted successfully',
    'box_out_of_money' => 'No enough money inside the box',
    'must_be_same_currency' => 'Both boxes must have same currency',
    'must_be_same_currency_check' => 'Both box and check must have same currency',
    'box_must_be_shekel' => 'Box must have shekel currency',
    //partnerships
    'partnership_created_successfully' => 'Partnership created successfully.',
    'partnership_updated_successfully' => 'Partnership updated successfully.',
    'partnership_not_found' => 'Partnership not found.',
    'retrieve_data_error' => 'Failed to retrieve data.',
    'something_wrong' => 'Something went wrong.',
    'validation_failed' => 'Validation failed.',

    //rewards
    'reward_created'=>'Reward created successfully',

    //punishments
    'punishment_created'=>'punishment created successfully',

    //notification
    'notificationSent'=>'Notification sent successfully',
    'notificationFailed' => 'Failed to send notification.',
    'firebaseInitError' => 'Could not initialize Firebase service.',
    'firebaseSendError' => 'Firebase failed to send the notification.',
    'firebaseUnknownError' => 'An unknown error occurred while sending notification.',
    //employee_orders
    'employee_order_created'=>'employee order was created successfully',
    'status_upated'=>'order status was updated successfully',

    'only_one_extra_hours'=>'you can either add extra normal hours or overtime hours',
    'one_extra_hours_should_filled' => 'you should either add extra normal hours or overtime hours',
    //profit sales
    'profit_sale_created_successfully' => 'profit sale was created successfully',

    //instant sales
    'instant_sale_created_successfully' => 'instant sale was created successfully',
    'sale_attached' => 'sale was attached to project successfully',
    'cant_be_project_type'=>'Product is not attached in any project yet',
    //outgoing_checks
    'check_created_successfully'=>'outgoing check was successfully created',

    'outgoing_check_not_found' => 'outgoing check was not found',
    'outgoing_check_cancelled' => 'outgoing check was cancelled successfully',
    'check_cashed' =>'check was cashed successfully',
    'one_person_required' => 'Either the customer or the seller must be provided.',
    'only_one_person_allowed' => 'Please provide either the customer or the seller, not both.',
    'outgoing_check_returned' => 'outgoing check was returned successfully',
    'outgoing_check_cashed' => 'outgoing check was cashed successfully',

    //incoming checks
    'must_select_customer_or_seller'=>'you need to select either a customer or a seller',
    'must_select_either_customer_or_seller'=>'select either seller or customer, not both',
    'must_select_employee' => 'you need to select employee',
    'must_select_box' =>'you need to select a box',
    'must_select_choice'=>'you need to select a choice',
    'must_select_perosn' => 'You need to select at most one person',
    'must_select_one_perosn' =>'You need to select only one person',
    'incoming_check_created_successfully'=>'incoming check created successfully',
    'check_cancelled'=>'check was cancelled successfully',
    'check_returned' => 'incoming check was returned successfully',
    'check_updated' =>'Check was updated successfully',
    'must_select_incoming_or_outgoing'=>'Must select incoming check or outgoing check',
    'must_select_either_incoming_or_outgoing' => 'Must select either outgoing check or incoming check, not both',
    'check_deleted'=>'Check was deleted successfully',
    'cannot_delete_check' => 'Check needs to be not cashed yet or archived to be deleted',
    //logs
    'log_cancelled' => 'log was cancelled successfully',

    // project expenses
    'expense_created' => 'project expense was created successfully',


    //assets
    'asset_created' => 'Asset was created successfully',
    'asset_depreciated' => 'Assets were depreciated successfully',
    'asset_not_found'=>'Asset was not found',
    'asset_updated'=>'Asset was updated successfully',
    'asset_deleted'=>'Asset was deleted successfully',
    'cannot_depreciate'=>'Can not depreciate asset, the asset depreciation price is 0',

    //expenses
    'expense_created' => 'Expense was created successfully',
    'expense_updated' =>'Expense was updated successfully',

    //destructions
    'destruction_created' => 'Destruction was created successfully',

    'stcok_failed'=>'Product\'s stock is less than number of pieces',

    //qr
    'invalid_qr'=>'Invalid QR code',

    //treasuries
    'treasury_created'=>'Treasury was created successfully',
    'treasury_cancelled' =>'Treasury was cancelled successfully',
    //files
    'file_created' => 'File was created successfully',
    'file_deleted' => 'File was deleted successfully',
    // file boxes
    'fileBox_created' => 'File box was created successfully',
    'fileBox_cancelled'=>'File box was cancelled succesfully',
    //pictures
    'picture_created'=>'Picture/Video was created successfully',
    'picture_updated'=>'Picture/Video was updated successfully',
    'picture_deleted'=>'Picture/Video was deleted successfully',

    //papers
    'paper_created'=>'Paper was created successfully',
    'file_box_not_for_treasury_selected'=>'File box doesn\'t belong to the treasury selected',
    'file_not_for_fil_box_selected'=>'File  doesn\'t belong to the file box selected',
    'paper_cancelled'=>'Paper was cancelled successfully',
    'paper_updated' => 'Paper was updated successfully',

        //stocks
    'product_updated'=>'Product was updated successfully',
    'product_created'=>'Product was created successfully',

    // closeouts
    'closeout_added'=>'Product was added to close outs successfully',
    'closeout_status_updated'=>'close out status was archieved',
    'cant_create_closeout'=>'Product already in close outs',

    'cant_sale'=>'Quantity is more than the product\'s stock',

    // combinations
    'combination_created'=>'Combination was created successfully',

    //bills and returns
    'bill_added'=>'Bill was added successfully',
    'bill_quantity_added'=>'Bill Quantity was added successfully',
    'product_status_updated'=>'Product status was updated successfully',
    'product_extra_was_purchased'=>'Extra product was purchased',
    'bill_cancelled'=>'Bill is cancelled',
    'bill_was_delivered'=>'Bill was delivered successfully',
    'entered_amount_bigger_than_quantity'=>'Entered amount is bigger than existing quantity',
    'must_be_status_extra'=>'Item\'s status must be extra',
    'must_be_status_securities'=>'Bill must be in securities',
    'bill_was_delivered'=>'Bill was delivered succesfully',
    'return_products_added'=>'Return purchases was added successfully',
    'return_delivered'=>'Return purchases was delivered successfully',
    'can_only_change_status_once'=>'Can only change product status once',
    'product_extra_or_not_compatible'=>'Product status must be extra or not compatible',
    'product_was_delivered'=>'Product was delivered successfully',
    'must_be_status_not_compatible'=>'Status must be not compatible',

    //product dev
    'prodev_step_updated'=>'Product development step was updated successfully',
    'prodev_created'=>'Product development was created successfully',

    // payment and recieve
    'payment_success'=>'Payment was added successfully',
    'receive_success'=>'Receivement was done successfully',
    'must_enter_box_value' => 'Must enter box value',


        //updated goals
    'form_must_be_employee' => 'Form must be employee.',
    'form_must_be_people' => 'Form must be people.',
    'form_must_be_box' => 'Form must be box.',
    'must_select_one_choice' => 'You must select only one of the available choices.',
    'invalid_form_for_sell_type' => 'Invalid form for total sell type.',
    'invalid_form_for_purchase_type' => 'Invalid form for total purchase type.',
    'invalid_form_for_general_type' => 'Invalid form for this type.',
    'form_does_not_match_selected_field' => 'Form does not match the selected field.',
    'must_select_one_person'=>'Must select one person to pay',

    // updated tasks
    'enter_recurrence_time'=>'Recurrence time is required',
    'enter_one_recurrence_time'=>'Enter only one recurrence time value',
    'invalid_monthly_recurrence_day'=>'Invalid day for monthly recurrence',
    'end_date_before_start'=>'End date must be after the start date',
    'cannot_update_recurrence_task' =>'Recurrence task can not be updated',



    //updated checks
    'check_cashed_from_box'=>'Check was cashed from box successfully',
    
    //updated debts
    'currency_shekel'=>'Box currency must be shekel',
];
