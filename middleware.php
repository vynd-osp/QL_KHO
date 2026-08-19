<?php

function can($cap){
    // quản lý => full quyền
    if($_SESSION['role'] == "Quản lý") return true;

    // Nhân viên => danh sách permission được phép
    $staff_permissions = [

        // chức năng nhóm 3: cửa hàng
        "store_add",     // 3.1
        "store_edit",    // 3.2
        "store_search",  // 3.4

        // nhóm 4: sản phẩm
        "product_add",   // 4.1
        "product_edit",  // 4.2
        "product_search",// 4.4
        "product_stock", // 4.5

        // nhóm 5 nhập kho
        "import_add",   //5.1
        "import_edit",  //5.2
        "import_search",//5.4
        "import_status",//5.6
        // alias / legacy keys for status change actions (allow Nhân viên to change statuses)
        "change_import_status",
        "status_change_import",

        // nhóm 6 xuất kho
        "export_add",   //6.1
        "export_edit",  //6.2
        "export_search",//6.4
        "export_status",//6.6
        // alias / legacy keys for status change actions (allow Nhân viên to change statuses)
        "change_export_status",
        "status_change_export",
    ];

    return in_array($cap, $staff_permissions);
}
