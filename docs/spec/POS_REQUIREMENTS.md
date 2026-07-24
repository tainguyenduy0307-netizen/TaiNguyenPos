# Yêu Cầu Dự Án POS

## 1. Mô Hình Chung

- Một ứng dụng, một source code và một database.
- Có ba tài khoản cố định: DAY, NIGHT và TỔNG HỢP.
- DAY và NIGHT có toàn quyền quản trị trong phạm vi dữ liệu của mình.
- Tài khoản TỔNG HỢP chỉ xem báo cáo gộp DAY + NIGHT, không được bán hàng hoặc chỉnh dữ liệu.
- Toàn bộ giao diện người dùng phải bằng tiếng Việt tự nhiên, đúng nghiệp vụ bán lẻ Việt Nam.

## 2. Hai Phiên Đăng Nhập

- DAY truy cập qua `http://localhost:8080`.
- NIGHT truy cập qua `http://localhost:8081`.
- Hai địa chỉ cùng dùng một app và database nhưng phải có session/cookie tách biệt.
- Có thể mở đồng thời DAY và NIGHT trong cùng một trình duyệt mà không xung đột.
- Cổng chỉ dùng để tách session, không được dùng làm nguồn xác định business unit.
- Backend phải lấy phạm vi DAY/NIGHT từ tài khoản đã xác thực.
- Người dùng không được tự chọn hoặc chuyển DAY/NIGHT trên giao diện.

## 3. Dữ Liệu Dùng Chung

- Danh mục sản phẩm.
- Mã hàng.
- Mã vạch.
- Tên sản phẩm.
- Nhóm hàng.
- Đơn vị tính.
- Giá bán hiện tại.
- Giá vốn hiện tại.
- Khách hàng.
- Điểm khách hàng.
- Lịch sử tổng của khách hàng.
- Mẫu in hóa đơn.
- File Excel cập nhật danh mục, giá bán và giá vốn.

## 4. Dữ Liệu Tách Riêng DAY/NIGHT

- Tồn kho.
- Hóa đơn.
- Dãy số hóa đơn.
- Giao dịch bán hàng.
- Doanh thu.
- Số lượng đã bán.
- Hủy hóa đơn.
- Trả hàng.
- Nhập/xuất hoặc điều chỉnh kho.
- Thu và chi.
- Báo cáo.
- Nhật ký giao dịch.

DAY không được xem, sửa, hủy hoặc trả giao dịch NIGHT và ngược lại.

## 5. Tồn Kho

- DAY chỉ thấy tồn kho DAY.
- NIGHT chỉ thấy tồn kho NIGHT.
- Không hiển thị tồn kho bên còn lại.
- Chỉ ghi đơn giản “Tồn kho”, không ghi “Tồn DAY” hoặc “Tồn NIGHT”.
- Tồn kho có thể bằng 0 hoặc âm.
- Tồn kho không được chặn bán hàng.

## 6. Hóa Đơn

- DAY và NIGHT có hai dãy số riêng.
- Số hóa đơn hiển thị dạng 0001, 0002, 0003.
- Không có tiền tố DAY/NIGHT.
- Không ghi câu “Hóa đơn số”.
- Khi DAY đã có 0003, hóa đơn đầu tiên của NIGHT vẫn là 0001.

## 7. Giá Vốn

- Giá vốn hiện tại dùng chung.
- Khi bán, từng dòng hóa đơn phải lưu giá vốn tại thời điểm bán.
- Cập nhật giá vốn mới chỉ ảnh hưởng các giao dịch sau thời điểm cập nhật.
- Không được tính lại hoặc thay đổi lợi nhuận lịch sử.

## 8. Khách Hàng Và Điểm

- Khách hàng dùng chung giữa DAY và NIGHT.
- Điểm dùng chung.
- Một khách có thể mua buổi sáng và buổi tối, điểm vẫn cộng vào cùng hồ sơ.
- Có thể sử dụng điểm ở cả DAY và NIGHT.
- Quy tắc: mỗi 150.000 đồng được 1 điểm.
- Ô tìm khách nhận tên hoặc số điện thoại.
- Nếu không tìm thấy, nút + mở form tạo nhanh.
- Nếu giá trị nhập giống số điện thoại, tự điền vào trường Số điện thoại.
- Nếu là chữ, tự điền vào trường Tên khách hàng.
- Sau khi lưu, tự chọn khách mới vào hóa đơn hiện tại.
- Hồ sơ khách phải có trường Ghi chú.
- Ghi chú phải xem và sửa được với cả khách mới và khách đã tồn tại.
- Bỏ các trường Email, Ngày sinh, Điện thoại 2, Giới tính, Tỉnh/thành, Nhân viên quản lý, Địa chỉ 2, Nhóm, Công ty và Bộ phận.

## 9. Excel

- File nguồn đã duyệt: `docs/reference-data/Danh_muc_san_pham_POS.xlsx`.
- Các cột nguồn: Mã hàng hóa, Tên hàng hóa, Nhóm hàng, Đơn vị tính, Giá bán, Giá vốn.
- Các dòng trong sheet Can_kiem_tra cũng được người dùng duyệt và không được tự ý loại bỏ.
- Có chức năng import danh mục ban đầu.
- Có chức năng cập nhật giá bán và giá vốn chung bằng Excel.
- Tồn kho DAY/NIGHT không được trộn vào luồng cập nhật giá chung.

## 10. Chế Độ Thu Ngân

Màn hình tối giản gồm:

- Ô tìm/quét sản phẩm.
- Tab hoặc mã đơn hiện tại.
- Bảng sản phẩm: tên, đơn giá, số lượng, thành tiền và xóa dòng.
- Tìm/chọn/tạo khách hàng.
- Điểm và ghi chú khách hàng.
- Tổng tiền.
- Giảm giá nếu dùng.
- Tiền khách trả.
- Tiền thừa.
- Nút thanh toán.
- Nút in hóa đơn.

Khi nhập tiền khách trả, hệ thống tự tính tiền thừa.

Có thể nhập trực tiếp hoặc dùng nút mệnh giá nhanh.

Nếu khách trả thiếu thì hiển thị số tiền còn thiếu và không hoàn tất thanh toán tiền mặt.

## 11. Chế Độ Quản Trị

- Có giao diện quản trị riêng theo bố cục menu ngang tham khảo POS365.
- DAY chỉ xem và quản trị DAY.
- NIGHT chỉ xem và quản trị NIGHT.
- TỔNG HỢP chỉ xem báo cáo DAY + NIGHT.
- Báo cáo tối thiểu: 1 ngày, 7 ngày và 30 ngày.
- Hiển thị số đơn, doanh thu, giá vốn, lợi nhuận, khách hàng và hàng đã mua.

## 12. Mẫu In K58

- Một mẫu in dùng chung cho DAY và NIGHT.
- Không in POS365, URL, http hoặc quảng cáo phần mềm.
- Có trang Cài đặt → Mẫu in hóa đơn trong quản trị.
- Người dùng có thể bật/tắt và chỉnh nội dung các khối:
  logo, tên cửa hàng, địa chỉ, điện thoại/Zalo, mã số thuế, tiêu đề, số hóa đơn, ngày giờ, khách hàng, bảng hàng hóa, giảm giá, tổng cộng, tiền khách đưa, tiền thừa, số tiền bằng chữ, chính sách đổi hàng và lời cảm ơn.
- Cho phép thay đổi thứ tự khối, căn lề và cỡ chữ trong giới hạn an toàn.
- Có xem trước khổ K58 trước khi lưu.
- Không cho sửa HTML tự do.

## 13. Ứng Dụng Windows

- Thu ngân không được phải mở terminal, nhập Docker hoặc gõ localhost.
- Sau này có shortcut/app để tự mở Docker, khởi động POS, chờ dịch vụ sẵn sàng và mở đúng giao diện.
- Có cách tắt POS an toàn.
- Có shortcut riêng cho DAY và NIGHT.

## 14. Ngoài Phạm Vi

- Không cần mở ca, đóng ca hoặc chốt quỹ.
- Không cần báo cáo chuyên sâu tiền mặt/chuyển khoản.
- Không cần DAY/NIGHT xử lý chéo dữ liệu.
- Không cần thiết kế thêm chức năng chưa được yêu cầu.

## Tiêu Chí Bắt Buộc

- Bảo mật cách ly DAY/NIGHT là tiêu chí không được vi phạm.
- Lưu giá vốn lịch sử là tiêu chí không được vi phạm.
- Tiếng Việt 100% là tiêu chí không được vi phạm.
- Tồn kho không chặn bán là tiêu chí không được vi phạm.
- Khả năng đăng nhập đồng thời DAY và NIGHT là tiêu chí không được vi phạm.
